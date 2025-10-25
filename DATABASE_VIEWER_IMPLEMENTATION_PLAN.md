# GigHive Database Viewer Implementation Plan

## Overview
Convert the "View in Database" button from opening external browser to displaying a native iPhone SwiftUI view that consumes the GigHive web server API.

---

## Phase 1: Server-Side Changes (PHP Repository)

### Goal
Add JSON output capability to existing `/db/database.php` endpoint without breaking HTML functionality.

### Changes Required

#### 1.1 Modify `src/Controllers/MediaController.php`

**Add new method after the existing `list()` method:**

```php
/**
 * Return media list as JSON instead of HTML
 */
public function listJson(): Response
{
    $rows = $this->repo->fetchMediaList();

    $counter = 1;
    $entries = [];
    foreach ($rows as $row) {
        $id        = isset($row['id']) ? (int)$row['id'] : 0;
        $date      = (string)($row['date'] ?? '');
        $orgName   = (string)($row['org_name'] ?? '');
        $duration  = self::secondsToHms(isset($row['duration_seconds']) ? (string)$row['duration_seconds'] : '');
        $durationSec = isset($row['duration_seconds']) && $row['duration_seconds'] !== null
            ? (int)$row['duration_seconds']
            : 0;
        $songTitle = (string)($row['song_title'] ?? '');
        $typeRaw   = (string)($row['file_type'] ?? '');
        $file      = (string)($row['file_name'] ?? '');

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $dir = ($ext === 'mp3') ? '/audio' : (($ext === 'mp4') ? '/video' : '');
        if ($dir === '' && ($typeRaw === 'audio' || $typeRaw === 'video')) {
            $dir = '/' . $typeRaw;
        }
        $url = ($dir && $file) ? $dir . '/' . rawurlencode($file) : '';

        $entries[] = [
            'id'               => $id,
            'index'            => $counter++,
            'date'             => $date,
            'org_name'         => $orgName,
            'duration'         => $duration,
            'duration_seconds' => $durationSec,
            'song_title'       => $songTitle,
            'file_type'        => $typeRaw,
            'file_name'        => $file,
            'url'              => $url,
        ];
    }

    $body = json_encode(['entries' => $entries], JSON_PRETTY_PRINT);
    return new Response(200, ['Content-Type' => 'application/json'], $body);
}
```

#### 1.2 Modify `db/database.php`

**Replace line 19 (`$response = $controller->list();`) with:**

```php
// Check if JSON format is requested via query parameter
$wantsJson = isset($_GET['format']) && $_GET['format'] === 'json';

// Route to appropriate method
$response = $wantsJson ? $controller->listJson() : $controller->list();
```

### Testing Phase 1

After deployment, test:

1. **HTML still works:**
   ```
   https://dev.gighive.app/db/database.php
   → Should return HTML table (existing behavior)
   ```

2. **JSON now available:**
   ```
   https://dev.gighive.app/db/database.php?format=json
   → Should return JSON array
   ```

3. **Authentication:**
   - Both endpoints should require BasicAuth (viewer/secretviewer)

### Expected JSON Response Format

```json
{
  "entries": [
    {
      "id": 123,
      "index": 1,
      "date": "2024-10-20",
      "org_name": "The Jazz Band",
      "duration": "03:45:12",
      "duration_seconds": 13512,
      "song_title": "Blue Moon",
      "file_type": "video",
      "file_name": "jazz_band_2024-10-20.mp4",
      "url": "/video/jazz_band_2024-10-20.mp4"
    }
  ]
}
```

---

## Phase 2: iOS App Changes (GigHive Repository)

### Goal
Create native SwiftUI views to display database contents using the new JSON API.

### Files to Create

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

#### 2.2 `GigHive/Sources/App/DatabaseAPIClient.swift`

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
        
        // Add BasicAuth (viewer/secretviewer)
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

#### 2.3 `GigHive/Sources/App/DatabaseView.swift`

```swift
import SwiftUI

struct DatabaseView: View {
    let baseURL: URL
    let basicAuth: (String, String)?
    let allowInsecure: Bool
    
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
                                                                          baseURL: baseURL)) {
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
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Done") {
                        dismiss()
                    }
                }
            }
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
            let client = DatabaseAPIClient(baseURL: baseURL, 
                                          basicAuth: basicAuth, 
                                          allowInsecure: allowInsecure)
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

#### 2.4 `GigHive/Sources/App/DatabaseDetailView.swift`

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
                    if let url = URL(string: entry.url, relativeTo: baseURL) {
                        openURL(url)
                    }
                }) {
                    HStack {
                        Image(systemName: entry.fileType == "video" ? "play.circle.fill" : "music.note")
                        Text(entry.fileType == "video" ? "Play Video" : "Play Audio")
                        Spacer()
                        Image(systemName: "arrow.up.right.square")
                    }
                }
                
                if let url = URL(string: entry.url, relativeTo: baseURL) {
                    ShareLink(item: url) {
                        HStack {
                            Image(systemName: "square.and.arrow.up")
                            Text("Share")
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

#### 2.5 Modify `GigHive/Sources/App/UploadView.swift`

**Step 1:** Add state variable after line 53 (after `@State private var successURL: URL?`):

```swift
@State private var showDatabaseView = false
```

**Step 2:** Replace lines 331-340 (the "View in Database" button section):

**OLD CODE:**
```swift
if let url = successURL {
    Button(action: {
        openURL(url)
    }) {
        Text("View in Database")
            .frame(maxWidth: .infinity)
    }
    .buttonStyle(GHButtonStyle(color: .green))
    .padding(.top, 8)
}
```

**NEW CODE:**
```swift
Button(action: {
    showDatabaseView = true
}) {
    Text("View in Database")
        .frame(maxWidth: .infinity)
}
.buttonStyle(GHButtonStyle(color: .green))
.padding(.top, 8)
.sheet(isPresented: $showDatabaseView) {
    DatabaseView(
        baseURL: base,
        basicAuth: ("viewer", "secretviewer"),
        allowInsecure: allowInsecureTLS
    )
}
```

**Note:** Button is now always visible (not dependent on `successURL`)

---

## Testing Phase 2

### Test Checklist:

1. **Basic Functionality:**
   - [ ] Button "View in Database" is visible
   - [ ] Tapping button opens native sheet view
   - [ ] Loading indicator appears while fetching data
   - [ ] List displays all media entries

2. **Search & Filter:**
   - [ ] Search bar filters by band name
   - [ ] Search bar filters by song title
   - [ ] Search bar filters by date
   - [ ] Search bar filters by file type

3. **Detail View:**
   - [ ] Tapping entry opens detail view
   - [ ] All metadata displays correctly
   - [ ] "Play Video/Audio" button opens media in browser
   - [ ] Share button works

4. **Error Handling:**
   - [ ] 401 error shows appropriate message
   - [ ] Network failure shows error with retry button
   - [ ] Empty database shows "No media found"

5. **UI/UX:**
   - [ ] Pull-to-refresh works
   - [ ] "Done" button dismisses view
   - [ ] Works on different iPhone sizes
   - [ ] Dark mode displays correctly
   - [ ] Insecure TLS setting is respected

---

## Rollback Plan

If issues arise:

### Server-Side Rollback:
Revert `db/database.php` line 19 to:
```php
$response = $controller->list();
```

### iOS Rollback:
Revert `UploadView.swift` lines 331-340 to original code that uses `successURL` and `openURL()`.

---

## Notes

- **Authentication:** Database viewer uses viewer/secretviewer credentials (read-only)
- **Upload credentials:** Remain admin/secretadmin (unchanged)
- **Backward Compatibility:** HTML view continues to work at `/db/database.php`
- **No Breaking Changes:** All existing functionality preserved

---

## Implementation Order

1. ✅ Phase 1: Server-side JSON API (PHP repository)
2. ✅ Test Phase 1 endpoints
3. ✅ Phase 2: iOS native views (GigHive repository)
4. ✅ Test Phase 2 functionality
5. ✅ Deploy to production

---

## Questions or Issues?

If you encounter any issues during implementation, check:

1. JSON endpoint returns valid JSON (test with curl or browser)
2. Authentication credentials are correct
3. URL construction in `DatabaseAPIClient` is correct
4. All new files are added to Xcode project
5. Import statements are correct
