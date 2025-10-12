# Database Import Process

This document explains how GigHive imports CSV data into the MySQL database, including the data transformation pipeline and configuration options.

## Overview

GigHive uses a multi-step process to transform a single source CSV file into normalized database tables. This process handles session data, musicians, songs, and media files while maintaining referential integrity.

## Format

The source CSV file contains the following fields:

- **t_title*** - Session title (mandatory)
- **d_date*** - Session date in YYYY-MM-DD format (mandatory)
- **t_description_x** - Session description
- **t_image** - Cover image URL/path
- **d_crew_merged*** - Comma-separated list of musicians (mandatory)
- **v_location** - Session location/venue
- **v_rating** - Session rating
- **v_jam summary** - Session summary text
- **v_pubDate** - Publication date
- **v_explicit** - Explicit content flag (true/false)
- **v_duration** - Session duration
- **v_keywords** - Comma-separated keywords
- **d_merged_song_lists*** - Comma-separated list of songs performed (mandatory)
- **f_singles** - Comma-separated list of media files
- **l_loops** - Comma-separated list of loop files

*Fields marked with asterisk (*) are mandatory for proper database import.

## Process Flow

![Database Import Process](/images/databaseImportProcess.png)

## Configuration

### Database Size Selection

You have the option to load a full database or just a sample database. While this option most likely won't be used, know that you have that ability. The import process is controlled by the `database_full` variable in your inventory group vars file:

**File:** `$GIGHIVE_HOME/ansible/inventories/group_vars/gighive.yml`

```yaml
# app flavor for build-time overlay; database_full kept as-is
app_flavor: gighive
database_full: false  # Set to true for full dataset, false for sample
```

- **`database_full: true`** → Uses `mysqlPrep_full.py` (processes all sessions)
- **`database_full: false`** → Uses `mysqlPrep_sample.py` (processes only 2 sessions: 2002-10-24 and 2005-03-03)

## File Locations

### Source Data
- **Source CSV:** `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv`

### Processing Scripts
- **Full Dataset:** `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_full.py`
- **Sample Dataset:** `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/loadutilities/mysqlPrep_sample.py`
- **Driver Script:** `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/loadutilities/doAllFull.sh`

### Output Directory
- **Generated CSVs:** `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/`

### Docker Configuration
- **Template:** `$GIGHIVE_HOME/ansible/roles/docker/templates/docker-compose.yml.j2`
- **Rendered:** `$GIGHIVE_HOME/ansible/roles/docker/files/docker-compose.yml` (on VM)

## Data Transformation Process

### CSV Preprocessing

The Python scripts transform the single source CSV into multiple normalized tables:

#### Input: `database.csv`
Single CSV with columns for sessions, musicians, songs, and files all in one row per session.

#### Output: Normalized CSVs
1. **`sessions.csv`** - Session metadata (date, location, crew, etc.)
2. **`musicians.csv`** - Unique musician names with IDs
3. **`session_musicians.csv`** - Many-to-many relationship between sessions and musicians
4. **`songs.csv`** - Unique songs with IDs (⚠️ **Critical Fix Applied**)
5. **`session_songs.csv`** - Many-to-many relationship between sessions and songs
6. **`files.csv`** - Media files (audio/video) with metadata
7. **`song_files.csv`** - Many-to-many relationship between songs and files
8. **`database_augmented.csv`** - Original CSV with added columns for reference


## Import Process Steps

### Manual Process

1. **Edit Source Data**
   ```bash
   # Edit the source CSV
   vim $GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/loadutilities/database.csv
   ```

2. **Configure Database Size**
   ```bash
   # Edit inventory file
   vim $GIGHIVE_HOME/ansible/inventories/group_vars/gighive.yml
   # Set database_full: true or false
   ```

3. **Generate Normalized CSVs**
   ```bash
   cd $GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/loadutilities
   
   # Run the appropriate script based on database_full setting
   python3 mysqlPrep_full.py    # if database_full: true
   # OR
   python3 mysqlPrep_sample.py  # if database_full: false
   ```

4. **Deploy to VM**
   ```bash
   # Ansible copies files and restarts containers
   ansible-playbook -i inventories/inventory_azure.yml playbooks/site.yml
   ```

5. **Rebuild Containers**
   ```bash
   # On the VM host
   cd $GIGHIVE_HOME/ansible/roles/docker/files
   ./rebuildForDb.sh
   ```
   The MySQL startup process will automatically consume the import files.

6. **Visit the db/database.php link to see the changes.**

### Automated Process

The entire process is automated through Ansible:

1. **Ansible determines** which script to use based on `database_full` setting
2. **Runs preprocessing** script to generate normalized CSVs
3. **Copies files** to VM host
4. **Restarts MySQL container** which triggers auto-import
5. **MySQL imports** all CSV files on container startup

## Database Schema

The import process creates the following table relationships:

![Database ERD](/images/databaseErd.png)

## Troubleshooting

### Common Issues

1. **Missing Files in Output**
   - **Check:** Ensure files are listed in the `f_singles` column of source CSV
   - **Check:** Verify file extensions are supported (mp3, mp4, etc.)

2. **Import Fails**
   - **Check:** CSV syntax and encoding (UTF-8)
   - **Check:** MySQL container logs for specific errors
   - **Check:** File permissions on CSV files

### Validation

After import, verify data integrity by executing the following SQL on the MySQL container:

```sql
-- Check for duplicate song associations (should be none after fix)
SELECT f.file_name, s.title, sess.date, sess.title as session_title
FROM files f
JOIN song_files sf ON f.file_id = sf.file_id
JOIN songs s ON sf.song_id = s.song_id
JOIN session_songs ss ON s.song_id = ss.song_id
JOIN sessions sess ON ss.session_id = sess.session_id
WHERE f.file_name LIKE '%19971230_2%'
ORDER BY sess.date;
```

## Best Practices

1. **Gighive has an automated nightly backup procedure that will backup the data in the database to this directory on the VM host:** `$GIGHIVE_HOME/ansible/roles/docker/files/mysql/dbScripts/backups`
2. **Test with sample dataset** (`database_full: false`) before full import
3. **Verify file associations** after import using the validation query above
4. **Use consistent naming** for media files (date_track format recommended)
5. **Keep track of changes** in the source CSV for audit purposes

## Security Notes

- CSV files may contain sensitive session information
- Ensure proper file permissions on the VM
- Database credentials are managed through Ansible vault in production
- Media file paths may expose directory structure

---

*This documentation reflects the current state after applying the critical fix for unique song IDs per session.*
