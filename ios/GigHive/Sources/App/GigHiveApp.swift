import SwiftUI

@main
struct GigHiveApp: App {
    var body: some Scene {
        WindowGroup {
            if #available(iOS 16.0, *) {
                NavigationStack {
                    UploadView { _ in }
                }
            } else {
                NavigationView {
                    UploadView { _ in }
                }
                .navigationViewStyle(StackNavigationViewStyle())
            }
        }
    }
}
