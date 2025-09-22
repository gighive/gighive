import SwiftUI

@main
struct GigHiveApp: App {
    @StateObject private var settings = SettingsStore()

    var body: some Scene {
        WindowGroup {
            NavigationView {
                UploadFormView()
                    .environmentObject(settings)
            }
        }
    }
}
