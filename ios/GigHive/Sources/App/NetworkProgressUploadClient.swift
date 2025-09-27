import Foundation
import UniformTypeIdentifiers

/// Network-aware upload client that tracks REAL network progress, not file reading progress
final class NetworkProgressUploadClient: NSObject {
    private let baseURL: URL
    private let basicAuth: (user: String, pass: String)?
    private let allowInsecure: Bool
    private var session: URLSession!
    
    // Progress tracking
    private var progressHandler: ((Int64, Int64) -> Void)?
    private var completion: ((Result<(status: Int, data: Data, requestURL: URL), Error>) -> Void)?
    private var currentUploadTask: URLSessionUploadTask?
    
    init(baseURL: URL, basicAuth: (String, String)?, allowInsecure: Bool) {
        self.baseURL = baseURL
        self.basicAuth = basicAuth
        self.allowInsecure = allowInsecure
        
        super.init()
        
        // Configure session for uploads with progress tracking
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 300
        config.timeoutIntervalForResource = 7200
        config.allowsExpensiveNetworkAccess = true
        config.allowsConstrainedNetworkAccess = true
        
        // Create session with proper delegates after super.init()
        if allowInsecure {
            self.session = URLSession(configuration: config, delegate: InsecureTrustUploadDelegate(uploadClient: self), delegateQueue: nil)
        } else {
            self.session = URLSession(configuration: config, delegate: self, delegateQueue: nil)
        }
    }
    
    /// Upload file with REAL network progress tracking
    func uploadFile(
        payload: UploadPayload,
        progressHandler: @escaping (Int64, Int64) -> Void,
        completion: @escaping (Result<(status: Int, data: Data, requestURL: URL), Error>) -> Void
    ) {
        self.progressHandler = progressHandler
        self.completion = completion
        
        Task {
            do {
                // Build URL
                let apiURL = baseURL
                    .appendingPathComponent("api")
                    .appendingPathComponent("uploads.php")
                var components = URLComponents(url: apiURL, resolvingAgainstBaseURL: false)
                components?.queryItems = [URLQueryItem(name: "ui", value: "json")]
                guard let finalURL = components?.url else { 
                    completion(.failure(URLError(.badURL)))
                    return
                }
                
                var request = URLRequest(url: finalURL)
                request.httpMethod = "POST"
                
                // Add basic auth
                if let auth = basicAuth {
                    let credentials = "\(auth.user):\(auth.pass)"
                    let encodedCredentials = Data(credentials.utf8).base64EncodedString()
                    request.setValue("Basic \(encodedCredentials)", forHTTPHeaderField: "Authorization")
                }
                
                request.setValue("application/json,text/html;q=0.9", forHTTPHeaderField: "Accept")
                
                // Create multipart body (without fake progress)
                let boundary = "Boundary-\(UUID().uuidString)"
                request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
                
                let bodyData = try await createMultipartBody(payload: payload, boundary: boundary)
                
                // Start upload task - progress will be tracked by delegate
                let task = session.uploadTask(with: request, from: bodyData)
                self.currentUploadTask = task  // Store reference for cancellation
                task.resume()
                
                // Note: Completion will be handled by URLSessionDataDelegate methods
                
            } catch {
                completion(.failure(error))
            }
        }
    }
    
    /// Cancel the current upload task
    func cancelUpload() {
        currentUploadTask?.cancel()
        currentUploadTask = nil
    }
    
    /// Create multipart body WITHOUT fake progress tracking
    private func createMultipartBody(payload: UploadPayload, boundary: String) async throws -> Data {
        let dateFormatter = DateFormatter()
        dateFormatter.dateFormat = "yyyy-MM-dd"
        
        var body = Data()
        
        func addField(name: String, value: String) {
            body.append("--\(boundary)\r\n".data(using: .utf8)!)
            body.append("Content-Disposition: form-data; name=\"\(name)\"\r\n\r\n".data(using: .utf8)!)
            body.append("\(value)\r\n".data(using: .utf8)!)
        }
        
        // Add form fields
        addField(name: "event_date", value: dateFormatter.string(from: payload.eventDate))
        addField(name: "org_name", value: payload.orgName)
        addField(name: "event_type", value: payload.eventType)
        if let v = payload.label, !v.isEmpty { addField(name: "label", value: v) }
        if let v = payload.participants, !v.isEmpty { addField(name: "participants", value: v) }
        if let v = payload.keywords, !v.isEmpty { addField(name: "keywords", value: v) }
        if let v = payload.location, !v.isEmpty { addField(name: "location", value: v) }
        if let v = payload.rating, !v.isEmpty { addField(name: "rating", value: v) }
        if let v = payload.notes, !v.isEmpty { addField(name: "notes", value: v) }
        
        // Add file
        let filename = payload.fileURL.lastPathComponent
        let mimeType = getMimeType(for: payload.fileURL)
        
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"file\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: \(mimeType)\r\n\r\n".data(using: .utf8)!)
        
        // Read file data in chunks (no progress tracking here - that's fake!)
        let fileHandle = try FileHandle(forReadingFrom: payload.fileURL)
        defer { 
            if #available(iOS 13.0, *) {
                try? fileHandle.close()
            } else {
                fileHandle.closeFile()
            }
        }
        
        let bufferSize = 5 * 1024 * 1024 // 5MB chunks
        while true {
            let chunk = fileHandle.readData(ofLength: bufferSize)
            if chunk.isEmpty { break }
            body.append(chunk)
            // NO FAKE PROGRESS HERE - real progress happens in URLSessionTaskDelegate
        }
        
        body.append("\r\n".data(using: .utf8)!)
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)
        
        return body
    }
    
    private func getMimeType(for url: URL) -> String {
        if #available(iOS 14.0, *) {
            if let type = UTType(filenameExtension: url.pathExtension), 
               let mimeType = type.preferredMIMEType {
                return mimeType
            }
        }
        
        switch url.pathExtension.lowercased() {
        case "mp4": return "video/mp4"
        case "mov": return "video/quicktime"
        case "m4a": return "audio/m4a"
        case "mp3": return "audio/mpeg"
        default: return "application/octet-stream"
        }
    }
}

// MARK: - URLSessionTaskDelegate (REAL Network Progress)
extension NetworkProgressUploadClient: URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64, totalBytesSent: Int64, totalBytesExpectedToSend: Int64) {
        // This is REAL network progress - bytes actually sent over the network!
        print("📊 Network Progress: \(totalBytesSent)/\(totalBytesExpectedToSend) bytes (\(Int((Double(totalBytesSent)/Double(totalBytesExpectedToSend))*100))%)")
        DispatchQueue.main.async {
            self.progressHandler?(totalBytesSent, totalBytesExpectedToSend)
        }
    }
    
    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        if let error = error {
            DispatchQueue.main.async {
                self.completion?(.failure(error))
            }
        }
    }
}

// MARK: - URLSessionDataDelegate
extension NetworkProgressUploadClient: URLSessionDataDelegate {
    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive response: URLResponse, completionHandler: @escaping (URLSession.ResponseDisposition) -> Void) {
        completionHandler(.allow)
    }
    
    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        // Collect response data
        let status = (dataTask.response as? HTTPURLResponse)?.statusCode ?? -1
        let requestURL = dataTask.originalRequest?.url ?? baseURL
        
        DispatchQueue.main.async {
            self.completion?(.success((status: status, data: data, requestURL: requestURL)))
        }
    }
}

/// Combined delegate for handling both insecure trust and progress tracking
class InsecureTrustUploadDelegate: NSObject, URLSessionDelegate, URLSessionTaskDelegate, URLSessionDataDelegate {
    private weak var uploadClient: NetworkProgressUploadClient?
    
    init(uploadClient: NetworkProgressUploadClient) {
        self.uploadClient = uploadClient
    }
    
    // Handle insecure trust challenges (same as InsecureTrustDelegate)
    func urlSession(_ session: URLSession, didReceive challenge: URLAuthenticationChallenge, completionHandler: @escaping (URLSession.AuthChallengeDisposition, URLCredential?) -> Void) {
        // Accept any certificate (insecure mode)
        if challenge.protectionSpace.authenticationMethod == NSURLAuthenticationMethodServerTrust {
            let credential = URLCredential(trust: challenge.protectionSpace.serverTrust!)
            completionHandler(.useCredential, credential)
        } else {
            completionHandler(.performDefaultHandling, nil)
        }
    }
    
    // Forward progress to NetworkProgressUploadClient
    func urlSession(_ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64, totalBytesSent: Int64, totalBytesExpectedToSend: Int64) {
        uploadClient?.urlSession(session, task: task, didSendBodyData: bytesSent, totalBytesSent: totalBytesSent, totalBytesExpectedToSend: totalBytesExpectedToSend)
    }
    
    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        uploadClient?.urlSession(session, task: task, didCompleteWithError: error)
    }
    
    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive response: URLResponse, completionHandler: @escaping (URLSession.ResponseDisposition) -> Void) {
        uploadClient?.urlSession(session, dataTask: dataTask, didReceive: response, completionHandler: completionHandler)
    }
    
    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        uploadClient?.urlSession(session, dataTask: dataTask, didReceive: data)
    }
}
