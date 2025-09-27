import Foundation
import UniformTypeIdentifiers

/// Clean, simplified upload client - DEPRECATED
/// Use NetworkProgressUploadClient instead for real network progress tracking
final class TUSUploadClient_Clean {
    private let baseURL: URL
    private let basicAuth: (user: String, pass: String)?
    private let allowInsecure: Bool
    private var networkClient: NetworkProgressUploadClient?
    
    init(baseURL: URL, basicAuth: (String, String)?, allowInsecure: Bool) {
        self.baseURL = baseURL
        self.basicAuth = basicAuth
        self.allowInsecure = allowInsecure
    }
    
    /// Simple upload method - delegates to NetworkProgressUploadClient for real progress
    func uploadFile(
        payload: UploadPayload,
        progressHandler: @escaping (Int64, Int64) -> Void,
        completion: @escaping (Result<(status: Int, data: Data, requestURL: URL), Error>) -> Void
    ) {
        // Use the new NetworkProgressUploadClient for real network progress tracking
        let networkClient = NetworkProgressUploadClient(
            baseURL: baseURL,
            basicAuth: basicAuth,
            allowInsecure: allowInsecure
        )
        self.networkClient = networkClient  // Store reference for cancellation
        
        networkClient.uploadFile(
            payload: payload,
            progressHandler: progressHandler,
            completion: completion
        )
    }
    
    /// Cancel the current upload
    func cancelUpload() {
        networkClient?.cancelUpload()
        networkClient = nil
    }
}
