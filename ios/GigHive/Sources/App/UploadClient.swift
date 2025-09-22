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

final class UploadClient {
    let baseURL: URL
    let session: URLSession
    let basicAuth: (user: String, pass: String)?

    init(baseURL: URL, basicAuth: (String,String)? = nil, useBackgroundSession: Bool = false) {
        self.baseURL = baseURL
        self.basicAuth = basicAuth
        if useBackgroundSession {
            // Note: background sessions are not supported in app extensions.
            // Use only in the main app when long-running transfers are desired.
            let cfg = URLSessionConfiguration.background(withIdentifier: "com.yourcompany.gighive.uploads")
            cfg.waitsForConnectivity = true
            cfg.allowsExpensiveNetworkAccess = true
            cfg.allowsConstrainedNetworkAccess = true
            self.session = URLSession(configuration: cfg)
        } else {
            let cfg = URLSessionConfiguration.ephemeral
            cfg.waitsForConnectivity = true
            cfg.allowsExpensiveNetworkAccess = true
            cfg.allowsConstrainedNetworkAccess = true
            cfg.timeoutIntervalForRequest = 120
            cfg.timeoutIntervalForResource = 600
            self.session = URLSession(configuration: cfg)
        }
    }
    func upload(_ payload: UploadPayload) async throws -> (status: Int, data: Data) {
        var req = URLRequest(url: baseURL.appendingPathComponent("/api/uploads.php"))
        req.httpMethod = "POST"

        let boundary = "Boundary-\(UUID().uuidString)"
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")

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

        req.httpBody = body

        let (data, response) = try await session.data(for: req)
        let status = (response as? HTTPURLResponse)?.statusCode ?? -1
        return (status, data)
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
}
