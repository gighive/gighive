import SwiftUI
import PhotosUI
import UniformTypeIdentifiers

// SwiftUI wrapper for PHPickerViewController (iOS 14+)
struct PHPickerView: UIViewControllerRepresentable {
    var selectionHandler: (URL?) -> Void

    func makeUIViewController(context: Context) -> PHPickerViewController {
        var config = PHPickerConfiguration()
        config.filter = .videos // we only pick videos from Photos here
        config.selectionLimit = 1
        let picker = PHPickerViewController(configuration: config)
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ uiViewController: PHPickerViewController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    final class Coordinator: NSObject, PHPickerViewControllerDelegate {
        let parent: PHPickerView
        init(_ parent: PHPickerView) { self.parent = parent }
        func picker(_ picker: PHPickerViewController, didFinishPicking results: [PHPickerResult]) {
            picker.dismiss(animated: true)
            guard let provider = results.first?.itemProvider else { parent.selectionHandler(nil); return }
            let typeId = UTType.movie.identifier
            if provider.hasItemConformingToTypeIdentifier(typeId) {
                provider.loadFileRepresentation(forTypeIdentifier: typeId) { url, _ in
                    guard let url = url else {
                        DispatchQueue.main.async { self.parent.selectionHandler(nil) }
                        return
                    }
                    // Copy to a persistent temp location so the file remains after dismissal
                    let ext = url.pathExtension.isEmpty ? "mov" : url.pathExtension
                    let tmp = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString).appendingPathExtension(ext)
                    do {
                        // Remove if exists
                        try? FileManager.default.removeItem(at: tmp)
                        try FileManager.default.copyItem(at: url, to: tmp)
                        DispatchQueue.main.async { self.parent.selectionHandler(tmp) }
                    } catch {
                        DispatchQueue.main.async { self.parent.selectionHandler(nil) }
                    }
                }
            } else {
                DispatchQueue.main.async { self.parent.selectionHandler(nil) }
            }
        }
    }
}

// SwiftUI wrapper for UIDocumentPickerViewController (Files)
struct DocumentPickerView: UIViewControllerRepresentable {
    var allowedTypes: [UTType]
    var onPick: (URL?) -> Void

    func makeUIViewController(context: Context) -> UIDocumentPickerViewController {
        let picker = UIDocumentPickerViewController(forOpeningContentTypes: allowedTypes, asCopy: true)
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ uiViewController: UIDocumentPickerViewController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    final class Coordinator: NSObject, UIDocumentPickerDelegate {
        let parent: DocumentPickerView
        init(_ parent: DocumentPickerView) { self.parent = parent }
        func documentPicker(_ controller: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) {
            guard let url = urls.first else { parent.onPick(nil); return }
            let isAccessed = url.startAccessingSecurityScopedResource()
            defer { if isAccessed { url.stopAccessingSecurityScopedResource() } }
            // Copy to temp to ensure we own a stable file URL
            let ext = url.pathExtension
            let tmp = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString).appendingPathExtension(ext)
            do {
                try? FileManager.default.removeItem(at: tmp)
                try FileManager.default.copyItem(at: url, to: tmp)
                parent.onPick(tmp)
            } catch {
                parent.onPick(nil)
            }
        }
        func documentPickerWasCancelled(_ controller: UIDocumentPickerViewController) {
            parent.onPick(nil)
        }
    }
}
