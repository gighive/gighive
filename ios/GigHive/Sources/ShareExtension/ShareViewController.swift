import UIKit
import UniformTypeIdentifiers

// Simple settings for ShareExtension
struct ShareSettings {
    let baseURLString: String = "https://example.com" // TODO: Configure
    let basicUser: String = "admin" // TODO: Configure  
    let basicPass: String = "password" // TODO: Configure
    let defaultOrgName: String = "Auto"
    let defaultEventType: String = "band"
    var autoGenerateLabel: Bool = true
}

final class ShareViewController: UIViewController {
    private var settings = ShareSettings()
    private let toggle = UISwitch()
    private let toggleLabel = UILabel()
    private let uploadButton = UIButton(type: .system)
    private var pendingURL: URL?

    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        setupUIIfNeeded()
        Task { await loadFirstEligibleItem() }
    }

    private func loadFirstEligibleItem() async {
        guard let items = extensionContext?.inputItems as? [NSExtensionItem] else { return }
        let providers = items.compactMap { $0.attachments }.flatMap { $0 }
        for provider in providers {
            if provider.hasItemConformingToTypeIdentifier(UTType.movie.identifier) ||
               provider.hasItemConformingToTypeIdentifier(UTType.audio.identifier) {
                do {
                    let url = try await loadFileURL(from: provider)
                    self.pendingURL = url
                    self.uploadButton.isEnabled = toggle.isOn
                    return
                } catch {
                    // Ignore and try next provider
                }
            }
        }
        // Nothing eligible; close extension
        self.extensionContext?.completeRequest(returningItems: nil)
    }

    private func upload(url: URL) async throws {
        // Simple upload implementation for ShareExtension
        // For now, just simulate success - you can implement actual upload later
        try await Task.sleep(nanoseconds: 1_000_000_000) // 1 second delay
        print("ShareExtension: Would upload \(url.lastPathComponent)")
    }

    private func loadFileURL(from provider: NSItemProvider) async throws -> URL {
        try await withCheckedThrowingContinuation { cont in
            provider.loadItem(forTypeIdentifier: UTType.item.identifier, options: nil) { item, error in
                if let error = error { cont.resume(throwing: error); return }
                if let url = item as? URL { cont.resume(returning: url); return }
                cont.resume(throwing: NSError(domain: "Share", code: -1, userInfo: [NSLocalizedDescriptionKey: "No URL"]))
            }
        }
    }

    // MARK: - UI
    private func setupUIIfNeeded() {
        guard view.subviews.isEmpty else { return }
        view.backgroundColor = .systemBackground

        toggle.isOn = settings.autoGenerateLabel
        toggle.addTarget(self, action: #selector(toggleChanged(_:)), for: .valueChanged)

        toggleLabel.text = "Autogenerate label?"
        toggleLabel.font = .systemFont(ofSize: 16)

        uploadButton.setTitle("Upload", for: .normal)
        uploadButton.addTarget(self, action: #selector(uploadTapped), for: .touchUpInside)
        uploadButton.isEnabled = false

        let stack = UIStackView(arrangedSubviews: [toggleLabel, toggle])
        stack.axis = .horizontal
        stack.spacing = 8

        let vstack = UIStackView(arrangedSubviews: [stack, uploadButton])
        vstack.axis = .vertical
        vstack.spacing = 16
        vstack.alignment = .leading

        vstack.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(vstack)
        NSLayoutConstraint.activate([
            vstack.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor, constant: 24),
            vstack.leadingAnchor.constraint(equalTo: view.layoutMarginsGuide.leadingAnchor),
        ])
    }

    @objc private func toggleChanged(_ sender: UISwitch) {
        settings.autoGenerateLabel = sender.isOn
        uploadButton.isEnabled = sender.isOn && (pendingURL != nil)
    }

    @objc private func uploadTapped() {
        guard toggle.isOn else { return }
        guard let url = pendingURL else { return }
        Task {
            do {
                try await upload(url: url)
            } catch {
                // Could present a basic alert, but closing silently to keep UX minimal
            }
            self.extensionContext?.completeRequest(returningItems: nil)
        }
    }

    private func formatYMD(_ date: Date) -> String {
        let df = DateFormatter()
        df.calendar = Calendar(identifier: .gregorian)
        df.locale = Locale(identifier: "en_US_POSIX")
        df.dateFormat = "yyyy-MM-dd"
        return df.string(from: date)
    }
}
