import Foundation
import UniformTypeIdentifiers

struct UploadPayload {
    var fileURL: URL
    var eventDate: Date
    var orgName: String
    var eventType: String
    var label: String?
    var participants: String?
    var keywords: String?
    var location: String?
    var rating: String?
    var notes: String?
}

// Insecure trust delegate — accepts any TLS certificate. Use ONLY when user opts in.
final class InsecureTrustDelegate: NSObject, URLSessionDelegate {
    static let shared = InsecureTrustDelegate()
    private override init() { super.init() }

    func urlSession(_ session: URLSession, didReceive challenge: URLAuthenticationChallenge, completionHandler: @escaping (URLSession.AuthChallengeDisposition, URLCredential?) -> Void) {
        if challenge.protectionSpace.authenticationMethod == NSURLAuthenticationMethodServerTrust,
           let trust = challenge.protectionSpace.serverTrust {
            completionHandler(.useCredential, URLCredential(trust: trust))
        } else {
            completionHandler(.performDefaultHandling, nil)
        }
    }
}

final class UploadClient {
    let baseURL: URL
    let session: URLSession
    let basicAuth: (user: String, pass: String)?
    let allowInsecure: Bool
    private var currentTUSClient: TUSUploadClient_Clean?

    init(baseURL: URL, basicAuth: (String,String)? = nil, useBackgroundSession: Bool = false, allowInsecure: Bool = false) {
        self.baseURL = baseURL
        self.basicAuth = basicAuth
        self.allowInsecure = allowInsecure
        if useBackgroundSession {
            // Note: background sessions are not supported in app extensions.
            // Use only in the main app when long-running transfers are desired.
            let cfg = URLSessionConfiguration.background(withIdentifier: "com.yourcompany.gighive.uploads")
            cfg.waitsForConnectivity = true
            cfg.allowsExpensiveNetworkAccess = true
            cfg.allowsConstrainedNetworkAccess = true
            if allowInsecure {
                self.session = URLSession(configuration: cfg, delegate: InsecureTrustDelegate.shared, delegateQueue: nil)
            } else {
                self.session = URLSession(configuration: cfg)
            }
        } else {
            let cfg = URLSessionConfiguration.ephemeral
            cfg.waitsForConnectivity = true
            cfg.allowsExpensiveNetworkAccess = true
            cfg.allowsConstrainedNetworkAccess = true
            cfg.timeoutIntervalForRequest = 120
            cfg.timeoutIntervalForResource = 600
            if allowInsecure {
                self.session = URLSession(configuration: cfg, delegate: InsecureTrustDelegate.shared, delegateQueue: nil)
            } else {
                self.session = URLSession(configuration: cfg)
            }
        }
    }
    
    /// Upload with chunked streaming for all files
    /// Uses memory-efficient chunked approach for better cancellation and consistent UX
    func uploadWithChunking(_ payload: UploadPayload, progress: ((Int64, Int64) -> Void)? = nil) async throws -> (status: Int, data: Data, requestURL: URL) {
        
        // Use chunked upload for ALL files for better cancellation and consistent UX
        return try await uploadWithTUS(payload: payload, progress: progress)
    }
    
    /// Upload using network-aware progress tracking for all files
    private func uploadWithTUS(payload: UploadPayload, progress: ((Int64, Int64) -> Void)? = nil) async throws -> (status: Int, data: Data, requestURL: URL) {
        // Use clean TUSUploadClient wrapper for real network progress tracking
        let tusClient = TUSUploadClient_Clean(
            baseURL: baseURL,
            basicAuth: basicAuth,
            allowInsecure: allowInsecure
        )
        self.currentTUSClient = tusClient  // Store reference for cancellation
        
        return try await withCheckedThrowingContinuation { continuation in
            tusClient.uploadFile(
                payload: payload,
                progressHandler: { completed, total in
                    progress?(completed, total)
                },
                completion: { result in
                    continuation.resume(with: result)
                }
            )
        }
    }
    
    func upload(_ payload: UploadPayload, progress: ((Int64, Int64) -> Void)? = nil) async throws -> (status: Int, data: Data, requestURL: URL) {
        // Build https://<base>/api/uploads.php?ui=json without percent-encoding the '?'
        let apiURL = baseURL
            .appendingPathComponent("api")
            .appendingPathComponent("uploads.php")
        var components = URLComponents(url: apiURL, resolvingAgainstBaseURL: false)
        components?.queryItems = [URLQueryItem(name: "ui", value: "json")]
        guard let finalURL = components?.url else { throw URLError(.badURL) }
        var req = URLRequest(url: finalURL)
        req.httpMethod = "POST"

        let boundary = "Boundary-\(UUID().uuidString)"
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        // Prefer JSON for programmatic handling; server supports ?ui=html for HTML confirmation.
        req.setValue("application/json,text/html;q=0.9", forHTTPHeaderField: "Accept")

        if let basic = basicAuth {
            let token = Data("\(basic.user):\(basic.pass)".utf8).base64EncodedString()
            req.setValue("Basic \(token)", forHTTPHeaderField: "Authorization")
        }

        let df = DateFormatter()
        df.dateFormat = "yyyy-MM-dd"

        var body = Data()
        func addField(name: String, value: String) {
            body.append("--\(boundary)\r\n".data(using: .utf8)!)
            body.append("Content-Disposition: form-data; name=\"\(name)\"\r\n\r\n".data(using: .utf8)!)
            body.append("\(value)\r\n".data(using: .utf8)!)
        }

        addField(name: "event_date", value: df.string(from: payload.eventDate))
        addField(name: "org_name", value: payload.orgName)
        addField(name: "event_type", value: payload.eventType)
        if let v = payload.label, !v.isEmpty { addField(name: "label", value: v) }
        if let v = payload.participants, !v.isEmpty { addField(name: "participants", value: v) }
        if let v = payload.keywords, !v.isEmpty { addField(name: "keywords", value: v) }
        if let v = payload.location, !v.isEmpty { addField(name: "location", value: v) }
        if let v = payload.rating, !v.isEmpty { addField(name: "rating", value: v) }
        if let v = payload.notes, !v.isEmpty { addField(name: "notes", value: v) }

        let filename = payload.fileURL.lastPathComponent
        let fileData = try Data(contentsOf: payload.fileURL)
        let mime = mimeType(for: payload.fileURL)

        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"file\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: \(mime)\r\n\r\n".data(using: .utf8)!)
        body.append(fileData)
        body.append("\r\n".data(using: .utf8)!)
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)

        // Use uploadTask so we can observe progress
        return try await withCheckedThrowingContinuation { cont in
            let task = session.uploadTask(with: req, from: body) { data, response, error in
                if let error = error { cont.resume(throwing: error); return }
                let status = (response as? HTTPURLResponse)?.statusCode ?? -1
                cont.resume(returning: (status, data ?? Data(), req.url ?? self.baseURL))
            }

            var obs: NSKeyValueObservation?
            if let progressCb = progress {
                let p = task.progress
                // Ensure totalUnitCount is set (URLSession sets this automatically for uploadTask(from:))
                obs = p.observe(\.completedUnitCount, options: [.new]) { prog, _ in
                    progressCb(prog.completedUnitCount, max(prog.totalUnitCount, 0))
                }
            }

            task.resume()

            // Cleanup observer when task completes
            task.taskDescription = "gighive.upload"
            // Using a lightweight completion handler cleanup via KVO lifetime tied to task completion
            // The observation will be deallocated when this scope exits after continuation resumes.
            _ = obs
        }
    }

    private func mimeType(for url: URL) -> String {
        if #available(iOS 14.0, *) {
            if let type = UTType(filenameExtension: url.pathExtension), let m = type.preferredMIMEType { return m }
        }
        switch url.pathExtension.lowercased() {
        case "mp4": return "video/mp4"
        case "mov": return "video/quicktime"
        case "m4a": return "audio/m4a"
        case "mp3": return "audio/mpeg"
        default: return "application/octet-stream"
        }
    }
    
    /// Cancel the current upload
    func cancelCurrentUpload() {
        currentTUSClient?.cancelUpload()
        currentTUSClient = nil
    }
}
