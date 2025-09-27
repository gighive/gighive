import SwiftUI

@main
struct GigHiveApp: App {
    var body: some Scene {
        WindowGroup {
            NavigationView {
                UploadView { _ in }
            }
        }
    }
}
