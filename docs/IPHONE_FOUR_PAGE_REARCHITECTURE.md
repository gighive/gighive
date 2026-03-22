# Four Page Rearchitecture

## Overview
Convert the "View in Database" button from opening external browser to displaying a native iPhone SwiftUI view that consumes the GigHive web server API.

---

## Phase 3: UploadView Refactor (Implemented)

### Scope

- Reuse existing UploadClient protocol and request shape; no server contract changes.
- Refactor UploadView to consume session:
  - baseURL from `AuthSession.baseURL`
  - BasicAuth from `AuthSession.credentials`
  - TLS bypass from `AuthSession.allowInsecureTLS`
- Keep picker/progress UI and existing debug logs unchanged.
- Permissions and routing:
  - If not logged in → route to Login with intendedRoute `.upload` (handled via alert + user action).
  - If server responds 401/403 → show inline message prompting re‑login as admin/uploader.

### Notes

- UploadView mirrors the shared TLS toggle on appear.
- Error messages:
  - 401/403: “You do not have permission to upload… re‑login as admin or uploader.”
  - Other statuses keep prior behavior.

### Testing Phase 3

- Not logged in → Upload prompts to login; after signing in, return to Upload.
- Logged in as permitted account → upload succeeds; progress lines and UI behave as before.
- Logged in without permission → 401/403 message shown; quick path to Login.
- Cert bypass ON/OFF → both paths function against local/valid TLS servers.

## Phase 2: iOS App Changes (GigHive Repository)

### Goal
Create native SwiftUI views to display database contents using the new JSON API.

### Architecture and Navigation (Implemented)

- **Views:** SplashView, LoginView, View Database, Upload.
- **SplashView:** Reuses the existing title heading format (bee logo + brand font/style) used on the current page. Shows three buttons: View the Database, Upload a File, Login.
- **Auth Flow:**
  - Upload: When not authenticated, tapping Upload routes to Login; after successful login, the app auto-navigates to Upload.
  - View Database: When not authenticated, tapping View routes to Login; after login, return to Splash and tap View Database again to proceed (auto-navigation for DB is not enabled).
- **Session:** In-memory session holds `baseURL`, `credentials`, `allowInsecureTLS`, and a derived `role` (viewer/admin). Optional "Remember on this device" persists credentials in Keychain per host.
- **Permissions:** Viewer can view database; admin can upload. If a viewer attempts upload, show guidance to re-login as admin.
 - **UI Consistency:** Reuse existing look and feel across all views (bee logo title header, fonts, colors, button styles like `GHButtonStyle`).
 - **Deployment Target:** iPhone 12 baseline (iOS 15+). Use iOS 15-compatible APIs with availability checks and fallbacks.
 - **Auth Enhancements:** Lightweight server validation on Sign In (calls JSON endpoint); optional device-only "Remember on this device" via Keychain per host.
 - **In-App Playback:** Play audio/video inside the app using AVPlayer/VideoPlayer with BasicAuth headers; keep Share as-is. Note TLS/ATS caveat for AVPlayer. Implemented via `MediaPlayerView`.
  - UX: Player sheet includes a visible Close button in the navigation bar.

### UI Messaging and Error Styling

- **Informational messages** (e.g., guidance on Splash, success/neutral notices): use the same orange color as the original page.
- **Errors** (e.g., invalid URL, auth failures, network errors): use the same red debug text style from the original upload page.
- Apply consistently across the four views.

### Debug Logging Convention

- Use the existing timestamped logger `logWithTimestamp(_:)` for all new user-interactive pages (Splash, Login, View Database, Upload variants, Detail).
- Log key user actions and lifecycle points, for example:
  - View appearances and dismissals
  - Button taps (Login, View Database, Upload, Retry, Play, Share)
  - Network call start/success/failure summaries (e.g., counts, status codes)
- Keep logs concise and action-oriented, e.g.:
  - `[time] [Login] Sign in started`
  - `[time] [Login] Auth success`
  - `[time] [DB] Loaded 123 entries`
  - `[time] [DB] Error: HTTP 401`

### High-Level Implementation Plan

- **AuthSession (in-memory)**
  - <span style="color:#0a0">NEW</span> `AuthSession` (`ObservableObject`) with:
    - `baseURL: URL?`
    - `credentials: (user, pass)?`
    - `allowInsecureTLS: Bool`
    - `role: .unknown | .viewer | .admin`
    - `intendedRoute: .viewDatabase | .upload`
  - Inject as `@EnvironmentObject` at app entry. No persistence.

- **SplashView**
  - Reuse existing title header with bee logo and brand font/style.
  - <span style="color:#0a0">NEW</span> `SplashView` with buttons:
    - View the Database → set `intendedRoute = .viewDatabase`
    - Upload a File → set `intendedRoute = .upload`
    - Login → present `LoginView`
  - Guard: If unauthenticated and user taps View/Upload, route to `LoginView` first; after login, continue to intended route.

- **LoginView**
  - <span style="color:#0a0">NEW</span> `LoginView` with:
    - Fields: Base URL, Username, Password
    - Toggle: Disable certificate checking
    - On Sign In: Perform lightweight validation (JSON fetch); on success, set session `baseURL`, `credentials`, `allowInsecureTLS`, `role = .unknown`; route to `intendedRoute`.
    - Optional: <span style="color:#0a0">NEW</span> "Remember on this device" toggle to save/delete creds in Keychain per host; prefill on appear if found.

- **Models and API Client**
  - <span style="color:#0a0">NEW</span> `MediaEntry` and `MediaListResponse` models.
  - <span style="color:#0a0">NEW</span> `DatabaseAPIClient` using `/db/database.php?format=json`, applying BasicAuth from session, respecting insecure TLS, throwing `DatabaseError`.

- **DatabaseView**
  - <span style="color:#0a0">NEW</span> `DatabaseView` uses session for `baseURL`, `credentials`, `allowInsecureTLS`.
  - Features: loading, error with Retry, empty state, list with search (band/song/date/type), pull-to-refresh, navigation to detail.

- **DatabaseDetailView**
  - <span style="color:#0a0">NEW</span> `DatabaseDetailView` with metadata, open-in-browser, and ShareLink.
  - <span style="color:#0a0">NEW</span> `DetailRow` helper for labeled values.

- **UploadView (Refactor)**
  - Remove embedded auth UI; use session credentials/TLS.
  - If unauthenticated → go to login. If viewer creds → show re-login-as-admin messaging and provide quick link to Login with intent `.upload`.

- **Navigation Guards**
  - Global: If `session.credentials == nil`, redirect to Login before View/Upload. After login, navigate to `intendedRoute`.

- **Implementation Order**
  - <span style="color:#0a0">NEW</span> `AuthSession`, <span style="color:#0a0">NEW</span> `SplashView`, <span style="color:#0a0">NEW</span> `LoginView`.
  - Wire navigation guards and intended-route handling.
  - <span style="color:#0a0">NEW</span> models and `DatabaseAPIClient`.
  - <span style="color:#0a0">NEW</span> `DatabaseView`, <span style="color:#0a0">NEW</span> `DatabaseDetailView`; refactor `UploadView`.

### Platform Compatibility

- **Minimum iOS**: iOS 14+ supported. New features degrade gracefully.
- **Navigation**: `NavigationStack` on iOS 16+, fallback to `NavigationView` on earlier iOS.
- **Search/Refresh**: `.searchable`/`.refreshable` used on iOS 15+; iOS 14 uses a manual search TextField.
- **Dismiss**: `@Environment(\.dismiss)` on iOS 15+; compatible approaches used on earlier iOS.
- **Share**: `ShareLink` on iOS 16+, with a `UIActivityViewController` fallback helper.
- **TLS**: Insecure TLS delegate available regardless of iOS version when enabled by user.

### Phased Implementation

- **Phase 0: Session foundation**
  - Create `AuthSession` and inject as `EnvironmentObject`. No UI changes; existing screens keep working.
  - Tests: app builds; session is accessible across views.

- **Phase 1: Entry + Login**
  - Add `SplashView` (bee logo header, three buttons) and `LoginView` (Base URL, Username, Password, Disable certificate checking).
  - Soft-routing only (no global guards yet). Verify session values and TLS toggle via a trivial request.

- **Phase 2: Database API and View**
  - Implement `MediaEntry`, `MediaListResponse`, and `DatabaseAPIClient`.
  - Add `DatabaseView` (list, search, refresh, detail open/share, errors) using session creds/TLS.
  - Enable navigation from Splash → DatabaseView when logged in.

- **Phase 3: Upload refactor**
  - Refactor `UploadView` to use session (remove auth UI). Add viewer-only messaging with link to re-login as admin.
  - Tests: admin can upload; viewer blocked with guidance.

- **Phase 4: Guards and polish**
  - Enable global guards: unauthenticated taps on View/Upload route to Login, then back to intended destination.
  - Final UI consistency, accessibility, and QA (dark mode, device sizes).

### Files to Create

#### 2.0 `GigHive/Sources/App/AuthSession.swift`

```swift
import Foundation
import SwiftUI

final class AuthSession: ObservableObject {
    @Published var baseURL: URL?
    @Published var credentials: (user: String, pass: String)?
    @Published var allowInsecureTLS: Bool = false
    @Published var role: UserRole = .unknown
    @Published var intendedRoute: AppRoute? = nil // .viewDatabase or .upload
}

enum UserRole { case unknown, viewer, admin }
enum AppRoute { case viewDatabase, upload }
```

#### 2.7 `GigHive/Sources/App/MediaPlayerView.swift`

```swift
// SwiftUI view that plays media in-app using AVPlayer. Accepts BasicAuth via AVURLAsset headers.
// iOS 15-compatible Close control: wraps content in NavigationView and dismisses via presentationMode.wrappedValue.dismiss().
```

#### 2.1 `GigHive/Sources/App/SplashView.swift`

```swift
import SwiftUI

struct SplashView: View {
    @EnvironmentObject var session: AuthSession

    var body: some View {
        VStack(spacing: 24) {
            // Reuse existing title header with bee logo and brand font/style
            TitleHeaderView() // existing component used on the current page

            Button("View the Database") {
                session.intendedRoute = .viewDatabase
            }
            .buttonStyle(GHButtonStyle(color: .blue))

            Button("Upload a File") {
                session.intendedRoute = .upload
            }
            .buttonStyle(GHButtonStyle(color: .green))

            Button("Login") { /* present LoginView */ }
            .buttonStyle(GHButtonStyle(color: .orange))
        }
        .padding()
    }
}
```

#### 2.2 `GigHive/Sources/App/LoginView.swift`

```swift
import SwiftUI

struct LoginView: View {
    @EnvironmentObject var session: AuthSession
    @State private var base: String = ""
    @State private var username: String = ""
    @State private var password: String = ""
    @State private var disableCertChecking: Bool = false
    @State private var isLoading = false
    @State private var errorMessage: String?

    var body: some View {
        VStack(spacing: 16) {
            TitleHeaderView()

            TextField("Base URL (e.g., https://staging.gighive.app)", text: $base)
                .textContentType(.URL)
                .keyboardType(.URL)
                .autocapitalization(.none)
                .disableAutocorrection(true)

            TextField("Username", text: $username)
                .textContentType(.username)
                .autocapitalization(.none)
                .disableAutocorrection(true)

            SecureField("Password", text: $password)
                .textContentType(.password)

            Toggle("Disable certificate checking", isOn: $disableCertChecking)

            if let error = errorMessage { Text(error).foregroundColor(.red) }

            Button(isLoading ? "Signing In…" : "Sign In") { Task { await signIn() } }
                .buttonStyle(GHButtonStyle(color: .orange))
                .disabled(isLoading)
        }
        .padding()
    }

    private func signIn() async {
        errorMessage = nil
        isLoading = true
        defer { isLoading = false }

        guard let url = URL(string: base) else { errorMessage = "Invalid URL"; return }
        session.baseURL = url
        session.credentials = (username, password)
        session.allowInsecureTLS = disableCertChecking
        // Role can be determined lazily by endpoint responses; set unknown initially
        session.role = .unknown
        // Navigation to intended route is handled by parent once credentials are set
    }
}
```

#### 2.1 `GigHive/Sources/App/DatabaseModels.swift`

```swift
import Foundation

struct MediaEntry: Codable, Identifiable {
    let id: Int
    let index: Int
    let date: String
    let orgName: String
    let duration: String
    let durationSeconds: Int
    let songTitle: String
    let fileType: String
    let fileName: String
    let url: String
    
    enum CodingKeys: String, CodingKey {
        case id, index, date, duration
        case orgName = "org_name"
        case durationSeconds = "duration_seconds"
        case songTitle = "song_title"
        case fileType = "file_type"
        case fileName = "file_name"
        case url
    }
}

struct MediaListResponse: Codable {
    let entries: [MediaEntry]
}
```

#### 2.4 `GigHive/Sources/App/DatabaseAPIClient.swift`

```swift
import Foundation

final class DatabaseAPIClient {
    let baseURL: URL
    let basicAuth: (user: String, pass: String)?
    let allowInsecure: Bool
    
    init(baseURL: URL, basicAuth: (String, String)?, allowInsecure: Bool = false) {
        self.baseURL = baseURL
        self.basicAuth = basicAuth
        self.allowInsecure = allowInsecure
    }
    
    func fetchMediaList() async throws -> [MediaEntry] {
        // Use /db/database.php?format=json
        var components = URLComponents(url: baseURL.appendingPathComponent("db/database.php"), 
                                       resolvingAgainstBaseURL: false)
        components?.queryItems = [URLQueryItem(name: "format", value: "json")]
        
        guard let url = components?.url else {
            throw DatabaseError.invalidURL
        }
        
        var request = URLRequest(url: url)
        
        // Add BasicAuth from user input
        if let auth = basicAuth {
            let credentials = "\(auth.user):\(auth.pass)"
            let base64 = Data(credentials.utf8).base64EncodedString()
            request.setValue("Basic \(base64)", forHTTPHeaderField: "Authorization")
        }
        
        let session: URLSession
        if allowInsecure {
            let config = URLSessionConfiguration.ephemeral
            session = URLSession(configuration: config, 
                               delegate: InsecureTrustDelegate.shared, 
                               delegateQueue: nil)
        } else {
            session = URLSession.shared
        }
        
        let (data, response) = try await session.data(for: request)
        
        guard let httpResponse = response as? HTTPURLResponse else {
            throw DatabaseError.invalidResponse
        }
        
        guard httpResponse.statusCode == 200 else {
            throw DatabaseError.httpError(httpResponse.statusCode)
        }
        
        let decoded = try JSONDecoder().decode(MediaListResponse.self, from: data)
        return decoded.entries
    }
}

enum DatabaseError: Error, LocalizedError {
    case invalidURL
    case invalidResponse
    case httpError(Int)
    
    var errorDescription: String? {
        switch self {
        case .invalidURL:
            return "Invalid database URL"
        case .invalidResponse:
            return "Invalid server response"
        case .httpError(let code):
            return "HTTP Error \(code)"
        }
    }
}
```

#### 2.5 `GigHive/Sources/App/DatabaseView.swift`

```swift
import SwiftUI

struct DatabaseView: View {
    // Consume session instead of passing creds directly
    @EnvironmentObject var session: AuthSession
    
    @State private var entries: [MediaEntry] = []
    @State private var filteredEntries: [MediaEntry] = []
    @State private var searchText = ""
    @State private var isLoading = false
    @State private var errorMessage: String?
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    
    var body: some View {
        NavigationView {
            VStack {
                if isLoading {
                    ProgressView("Loading database...")
                        .padding()
                } else if let error = errorMessage {
                    VStack(spacing: 16) {
                        Text("Error")
                            .font(.headline)
                        Text(error)
                            .foregroundColor(.red)
                            .multilineTextAlignment(.center)
                            .padding()
                        Button("Retry") {
                            Task { await loadData() }
                        }
                        .buttonStyle(GHButtonStyle(color: .blue))
                    }
                    .padding()
                } else if filteredEntries.isEmpty {
                    VStack(spacing: 16) {
                        Image(systemName: "tray")
                            .font(.system(size: 48))
                            .foregroundColor(.secondary)
                        Text("No media found")
                            .font(.headline)
                            .foregroundColor(.secondary)
                    }
                    .padding()
                } else {
                    List {
                        ForEach(filteredEntries) { entry in
                            NavigationLink(destination: DatabaseDetailView(entry: entry,
                                                                          baseURL: session.baseURL ?? URL(string: "https://example.com")!)) {
                                MediaEntryRow(entry: entry)
                            }
                        }
                    }
                    .searchable(text: $searchText, prompt: "Search by band, song, or date")
                    .refreshable {
                        await loadData()
                    }
                }
            }
            .navigationTitle("Media Database")
            .navigationBarTitleDisplayMode(.inline)
            .task {
                await loadData()
            }
            .onChange(of: searchText) { _ in
                filterEntries()
            }
        }
    }
    
    private func loadData() async {
        isLoading = true
        errorMessage = nil
        
        do {
            guard let baseURL = session.baseURL else { errorMessage = "Missing base URL"; isLoading = false; return }
            let client = DatabaseAPIClient(baseURL: baseURL,
                                          basicAuth: session.credentials,
                                          allowInsecure: session.allowInsecureTLS)
            entries = try await client.fetchMediaList()
            filteredEntries = entries
            isLoading = false
        } catch {
            errorMessage = error.localizedDescription
            isLoading = false
        }
    }
    
    private func filterEntries() {
        if searchText.isEmpty {
            filteredEntries = entries
        } else {
            let query = searchText.lowercased()
            filteredEntries = entries.filter { entry in
                entry.orgName.lowercased().contains(query) ||
                entry.songTitle.lowercased().contains(query) ||
                entry.date.contains(query) ||
                entry.fileType.lowercased().contains(query)
            }
        }
    }
}

struct MediaEntryRow: View {
    let entry: MediaEntry
    
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(entry.date)
                    .font(.caption)
                    .foregroundColor(.secondary)
                Spacer()
                Text(entry.fileType.uppercased())
                    .font(.caption2)
                    .padding(.horizontal, 6)
                    .padding(.vertical, 2)
                    .background(entry.fileType == "video" ? Color.blue.opacity(0.2) : Color.green.opacity(0.2))
                    .cornerRadius(4)
            }
            
            Text(entry.orgName)
                .font(.headline)
            
            HStack {
                Text(entry.songTitle)
                    .font(.subheadline)
                    .foregroundColor(.secondary)
                Spacer()
                Text(entry.duration)
                    .font(.caption)
                    .foregroundColor(.secondary)
            }
        }
        .padding(.vertical, 4)
    }
}
```

#### 2.6 `GigHive/Sources/App/DatabaseDetailView.swift`

```swift
import SwiftUI

struct DatabaseDetailView: View {
    let entry: MediaEntry
    let baseURL: URL
    @Environment(\.openURL) private var openURL
    
    var body: some View {
        List {
            Section("Media Info") {
                DetailRow(label: "Date", value: entry.date)
                DetailRow(label: "Band/Event", value: entry.orgName)
                DetailRow(label: "Song Title", value: entry.songTitle)
                DetailRow(label: "Duration", value: entry.duration)
                DetailRow(label: "File Type", value: entry.fileType)
                DetailRow(label: "File Name", value: entry.fileName)
            }
            
            Section {
                Button(action: {
                    // In-app playback via MediaPlayerView
                    // See In-App Playback section
                }) {
                    HStack {
                        Image(systemName: entry.fileType == "video" ? "play.circle.fill" : "music.note")
                        Text(entry.fileType == "video" ? "Play Video" : "Play Audio")
                        Spacer()
                        Image(systemName: "play.rectangle")
                    }
                }
                
                if let url = URL(string: entry.url, relativeTo: baseURL) {
                    if #available(iOS 16.0, *) {
                        ShareLink(item: url) {
                            HStack {
                                Image(systemName: "square.and.arrow.up")
                                Text("Share")
                            }
                        }
                    } else {
                        Button(action: { ShareHelper.present(url) }) {
                            HStack {
                                Image(systemName: "square.and.arrow.up")
                                Text("Share")
                            }
                        }
                    }
                }
            }
        }
        .navigationTitle("Media Details")
        .navigationBarTitleDisplayMode(.inline)
    }
}

struct DetailRow: View {
    let label: String
    let value: String
    
    var body: some View {
        HStack {
            Text(label)
                .foregroundColor(.secondary)
            Spacer()
            Text(value)
                .multilineTextAlignment(.trailing)
        }
    }
}
```

### Files to Modify

#### 2.8 Refactor `GigHive/Sources/App/UploadView.swift`

- Remove embedded authentication UI; rely on `AuthSession` for `baseURL`, `credentials`, and `allowInsecureTLS`.
- If unauthenticated, Upload shows an inline alert prompting the user to login (navigation to Login is initiated from Splash).
- On 401/403, show clear message: "You do not have permission to upload… re-login as admin or uploader."
- Keep existing look and feel (including header/title styles and `GHButtonStyle`).

---

### Architecture

#### In-App Playback

- `MediaPlayerView` handles in-app playback for video and audio files.
- `DatabaseDetailView` uses `MediaPlayerView` for playback (no external browser).

#### Media Proxy (Resource Loader)

- When "Disable Certificate Checking" is enabled, AVPlayer loads media through a proxy that uses our URLSession (with InsecureTrustDelegate) and BasicAuth.
- Implementation notes:
  - Custom scheme: convert https://host/path to gighive://host/path.
  - AVAssetResourceLoaderDelegate intercepts requests and forwards them via URLSession.
  - Adds Authorization header; mirrors Range headers precisely (bytes=offset-end), supports repeated small probes.
  - Streaming delivery: respond incrementally to AVPlayer using dataRequest.respond(with:) as URLSession delivers data; finish when complete or cancelled.
  - contentInformationRequest: set isByteRangeAccessSupported, contentLength (from Content-Range/Length), and UTI contentType (derived from MIME or file extension).
  - Handles cancellation and errors; logs request URL, ranges, HTTP status, and bytes delivered.
- Files:
  - `MediaResourceLoader.swift` (delegate + URLSession proxy)
  - `MediaPlayerView.swift` (creates AVURLAsset with custom scheme and sets loader delegate when bypass is enabled)

##### Testing (Proxy Mode)
- Play audio/video with cert bypass ON; verify logs show:
  - Proxy custom URL has host, path
  - Loader HTTP 206/200 and increasing bytes
  - Player item status moves to readyToPlay; timeControlStatus to playing

### In-App Playback UX

- Player is presented as a sheet with a NavigationView.
- A trailing toolbar "Close" button dismisses the sheet (iOS 15-compatible using presentationMode).
- Swipe to dismiss also works. Close action pauses playback and logs `[Player] Close tapped`.

---

### Testing Phase 2

### Test Checklist:

1. **Splash & Navigation:**
   - [ ] Splash header matches existing title/bee logo/branding
   - [ ] Three buttons visible: View Database, Upload a File, Login
   - [ ] Upload: When not logged in, routes to Login, then auto-opens Upload on success
   - [ ] View Database: When not logged in, routes to Login, then returns to Splash; user taps View Database again
2. **Login:**
   - [ ] Base URL, Username, Password fields work
   - [ ] Disable certificate checking toggle affects all network calls
   - [ ] Bad URL or creds produce clear errors
3. **View Database:**
   - [ ] Loads using session baseURL/credentials (Phase 1 JSON)
   - [ ] Loading indicator appears while fetching data
   - [ ] List displays all media entries

4. **Search & Filter:**
   - [ ] Search bar filters by band name
   - [ ] Search bar filters by song title
   - [ ] Search bar filters by date
   - [ ] Search bar filters by file type

5. **Detail View:**
   - [ ] Tapping entry opens detail view
   - [ ] All metadata displays correctly
   - [ ] "Play Video/Audio" plays media in-app (MediaPlayerView)
   - [ ] Share button works

6. **Error Handling:**
   - [ ] 401/403 show appropriate messages and guidance
   - [ ] Network failure shows error with retry button
   - [ ] Empty database shows "No media found"
7. **UI/UX:**
   - [ ] Pull-to-refresh works (View Database)
   - [ ] Works on different iPhone sizes
   - [ ] Dark mode displays correctly
   - [ ] Insecure TLS setting is respected globally
8. **Permissions:**
   - [ ] Viewer creds allow viewing but block upload with re-login messaging
   - [ ] Admin creds allow both viewing and uploading

---

## Rollback Plan

If issues arise:

### iOS Rollback:
Revert to the previous single-view flow: remove session-based guards and LoginView, restore UploadView’s embedded auth UI and external browser behavior for viewing if needed.

---

## Notes

- **Authentication:** Credentials are provided by the user at runtime (never stored in source or on device). Viewer creds allow read-only; admin creds required for upload.
- **Backward Compatibility:** HTML view continues to work at `/db/database.php`
- **No Breaking Changes:** All existing functionality preserved

---

## Implementation Order

1. Implement `AuthSession`, `SplashView`, and `LoginView` (TLS toggle).
2. Wire navigation guards and intended-route handling.
3. Refactor `DatabaseView` (Phase 1 JSON) and `UploadView` to use session; keep existing styling.
4. Execute testing checklist and fix issues.
5. Deploy to production.

---

## Questions or Issues?

If you encounter any issues during implementation, check:

1. JSON endpoint returns valid JSON (test with curl or browser)
2. Authentication credentials are correct
3. URL construction in `DatabaseAPIClient` is correct
4. All new files are added to Xcode project
5. Import statements are correct


---

## If We Drop iOS 14 Support (Future Enhancements)

Keep current minimal iOS 14 fallbacks for now. If/when we raise the deployment target to iOS 15+, we can simplify code and polish UX:

- **Navigation**
  - Remove `NavigationView` branches; standardize on iOS 15+ APIs (and `NavigationStack` on iOS 16+).
  - Replace `presentationMode` usages with `@Environment(\.dismiss)` everywhere.

- **DatabaseView**
  - Remove the manual TextField search fallback; use `.searchable` universally.
  - Use `.refreshable` universally and drop any iOS 14 “Refresh” button fallback.

- **Share & Dismiss**
  - Prefer `ShareLink` and keep `ShareHelper` only for non-ShareLink contexts, or remove fallback where appropriate.

- **Theming & Modifiers**
  - Simplify compatibility helpers in `Theme.swift` (use `.foregroundStyle`, `.tint`, and `.background(.ultraThinMaterial)` without fallbacks).
  - Remove `gh*` conditional wrappers that exist solely for iOS 14.

- **Cleanup iOS 14 Branches in Views**
  - Remove any `#unavailable(iOS 15)` or `#available` branches that only serve iOS 14.
  - Re-test UI on iOS 15/16 to confirm no regressions.

- **Optional (if later adopting iOS 16+ as baseline)**
  - Standardize on `NavigationStack` and path-based navigation/deep linking.
  - Use `ShareLink` everywhere and consider `PhotosPicker`/newer media APIs where beneficial.

### Proposed Steps (when we decide to drop iOS 14)

1. Raise deployment target to iOS 15 in project settings.
2. Remove iOS 14 branches in `DatabaseView`, `LoginView`, `MediaPlayerView`, and shared modifiers.
3. Simplify `Theme.swift` by removing compatibility code paths not needed on iOS 15+.
4. Retest features: search, refresh, playback, share, navigation, and upload on iOS 15/16.
5. Update this document to reflect iOS 15+ only.
