import Foundation

final class SettingsStore: ObservableObject {
    static let appGroupId = "group.com.gighive.gighive"
    private let defaults = UserDefaults(suiteName: SettingsStore.appGroupId) ?? .standard

    @Published var baseURLString: String {
        didSet { defaults.set(baseURLString, forKey: "baseURLString") }
    }
    @Published var basicUser: String {
        didSet { defaults.set(basicUser, forKey: "basicUser") }
    }
    @Published var basicPass: String {
        didSet { defaults.set(basicPass, forKey: "basicPass") }
    }

    // User defaults for auto-upload metadata
    @Published var defaultOrgName: String {
        didSet { defaults.set(defaultOrgName, forKey: "defaultOrgName") }
    }
    @Published var defaultEventType: String { // "band" or "wedding"
        didSet { defaults.set(defaultEventType, forKey: "defaultEventType") }
    }

    // Persisted UI preference: should the app auto-generate labels?
    @Published var autoGenerateLabel: Bool {
        didSet { defaults.set(autoGenerateLabel, forKey: "autoGenerateLabel") }
    }

    init() {
        self.baseURLString = defaults.string(forKey: "baseURLString") ?? "https://gighive" // default matches your current host var
        self.basicUser = defaults.string(forKey: "basicUser") ?? "admin"
        self.basicPass = defaults.string(forKey: "basicPass") ?? "secretadmin"
        self.defaultOrgName = defaults.string(forKey: "defaultOrgName") ?? "Enter band or event *"
        self.defaultEventType = defaults.string(forKey: "defaultEventType") ?? "band"
        self.autoGenerateLabel = defaults.object(forKey: "autoGenerateLabel") as? Bool ?? false
    }
}
