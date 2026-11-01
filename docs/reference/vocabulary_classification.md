# GigHive Documentation Vocabulary and Classification

## Purpose

This document is the core reference for organizing the current `docs/` corpus.

It serves two purposes:

1. Define the controlled vocabulary for document classification.
2. Provide a first-pass classification table for the current markdown documents.

Future visualizations, indexes, reading paths, and metadata frontmatter can be derived from this document.

## Classification Model

Each document is classified using five metadata categories:

- `type`
- `concern`
- `lifecycle`
- `audience`
- `aspect`

## Controlled Vocabulary

### `type`

- `howto`
- `runbook`
- `reference`
- `architecture`
- `feature`
- `proposal`
- `change`
- `rca`
- `debug`
- `index`

### `concern`

- `upload`
- `media`
- `streaming`
- `database`
- `api`
- `security`
- `authentication`
- `deployment`
- `infrastructure`
- `configuration`
- `performance`
- `operability`
- `observability`

### `lifecycle`

- `proposed`
- `active`
- `superseded`
- `historical`

### `audience`

- `developer`
- `operator`
- `admin`
- `maintainer`
- `qa`
- `architect`

### `aspect`

- `functional`
- `non_functional`
- `mixed`

## Interpretation Rules

### `type`

- `howto`: task-oriented guidance for accomplishing something.
- `runbook`: operational steps for deployment, recovery, maintenance, or incident handling.
- `reference`: stable factual material, inventories, contracts, schemas, or summaries.
- `architecture`: structural or system-level design explanation.
- `feature`: product or capability description focused on user-visible behavior.
- `proposal`: planned or recommended future direction.
- `change`: implementation note, change record, or documented modification.
- `rca`: root-cause analysis or problem retrospective.
- `debug`: investigation and troubleshooting guidance.
- `index`: navigation hub or top-level catalog.

### `concern`

Use one or two primary concerns where possible.

Hierarchy note:

- `infrastructure`
  - includes `iac`
    - includes Ansible- and Docker-specific material

So Ansible and Docker documents are classified primarily as `infrastructure` rather than as standalone concern values.

### `lifecycle`

- `proposed`: planned, future-looking, or not yet implemented.
- `active`: current and useful as of now.
- `superseded`: retained for context but replaced by a newer direction.
- `historical`: records past work, prior decisions, or one-time change context.

### `aspect`

- `functional`: mainly about product behavior or user-visible workflows.
- `non_functional`: mainly about security, resiliency, deployment, operability, performance, or maintainability.
- `mixed`: materially covers both functional and non-functional concerns.

## Notes on This First Pass

- This is a pragmatic first-pass classification based on current filenames and representative document content.
- Some entries are necessarily approximate and may be refined later.
- A few docs blend multiple document types; the table chooses the dominant type.
- Hidden macOS resource-fork files such as `._*.md` are intentionally excluded.
- Non-markdown files are intentionally excluded.

## Classification Table

| Document | Type | Concern | Lifecycle | Audience | Aspect | Notes |
|---|---|---|---|---|---|---|
| `README.md` | index | infrastructure, deployment | active | developer, operator | mixed | Top-level docs entry and setup orientation. |
| `index.md` | index | infrastructure, operability | active | developer, operator, admin | mixed | HTML-like navigation hub for rendered docs site. |
| `PREREQS.md` | reference | deployment, infrastructure | active | operator, developer | non_functional | Environment prerequisites for install/build. |
| `COMMON.md` | reference | configuration, infrastructure | active | developer, operator | non_functional | Shared/common repository guidance. |
| `COMMON-README-MAINTENANCE-GUIDE.md` | howto | operability, configuration | active | maintainer, developer | non_functional | README and docs maintenance guidance. |
| `DEPENDENCIES.md` | reference | infrastructure, configuration | active | developer, maintainer | non_functional | Dependency inventory/reference. |
| `README-DEPENDENCIES.md` | reference | infrastructure, configuration | active | developer, maintainer | non_functional | Dependency-oriented supporting reference. |
| `API_CURRENT_STATE.md` | reference | api | active | developer, architect | mixed | Current API state and shape. |
| `internalEndpoints.md` | reference | api, configuration | active | developer | mixed | Internal endpoint catalog. |
| `POTENTIAL_API_CLEANUP_IF_DESIRED.md` | proposal | api | proposed | developer, architect | mixed | Future-facing API cleanup ideas. |
| `TELEMETRY.md` | reference | observability, api | active | developer, operator, architect | non_functional | Telemetry and measurement guidance. |
| `SECURITY.md` | reference | security, authentication | active | developer, operator, admin | non_functional | Security overview/reference. |
| `security-upgrade.md` | proposal | security, authentication | proposed | developer, operator, architect | non_functional | Future auth/security roadmap. |
| `security_apache_realms.md` | reference | security, authentication | active | developer, operator, admin | non_functional | Auth realm/security reference. |
| `security_remediations_20260218.md` | change | security | historical | developer, operator | non_functional | Security remediation change record. |
| `JWT_AUTH_MIGRATION_MAPPING.md` | proposal | authentication, api | proposed | developer, architect | mixed | Migration mapping toward JWT-based auth. |
| `HTPASSWD_CHANGES.md` | change | authentication, security | historical | developer, operator | non_functional | Implemented password/auth file change record. |
| `APP_TERMS_OF_SERVICE.md` | reference | security, operability | active | admin, maintainer | non_functional | Policy/legal-facing application terms. |
| `privacy.md` | reference | security | active | admin, maintainer | non_functional | Privacy policy-style reference. |
| `gighive_content_policy.md` | reference | security, operability | active | admin, maintainer | non_functional | Content-policy reference. |
| `LICENSE_AGPLv3.md` | reference | operability | active | maintainer, admin | non_functional | License reference. |
| `LICENSE_COMMERCIAL.md` | reference | operability | active | maintainer, admin | non_functional | Commercial licensing reference. |
| `feature_set.md` | architecture | infrastructure, operability | active | architect, developer, operator | non_functional | Architecture/capability overview. |
| `FOUR_PAGE_REARCHITECTURE.md` | architecture | api, media | proposed | architect, developer | mixed | Structural redesign discussion. |
| `FLAVOR_CONTRACT.md` | reference | configuration, infrastructure | active | developer, architect | mixed | Flavor/system contract reference. |
| `BOOTSTRAP_PHASE1.md` | proposal | deployment, infrastructure | proposed | operator, architect, developer | non_functional | Early-phase bootstrap plan. |
| `migrate-bootstrap-to-ansible.md` | proposal | deployment, infrastructure | proposed | operator, architect, developer | non_functional | Migration proposal for bootstrap workflow. |
| `autoinstall.md` | howto | deployment, infrastructure | active | operator | non_functional | Auto-install procedure guidance. |
| `autostart_vm_implementation.md` | change | infrastructure, deployment | historical | operator, developer | non_functional | VM autostart implementation record. |
| `setup_instructions_fullbuild.md` | howto | deployment, infrastructure | active | operator, developer | non_functional | Full build instructions. |
| `setup_instructions_quickstart.md` | howto | deployment, infrastructure | active | operator, developer | non_functional | Quickstart instructions. |
| `process_download_quickstart_versus_full_build.md` | reference | deployment, operability | active | operator, developer | non_functional | Comparison/reference between setup modes. |
| `process_download_quickstart_rebuild_criteria.md` | runbook | deployment, operability | active | operator, maintainer | non_functional | Rebuild decision criteria and process. |
| `process_download_directory_for_tgz_design.md` | architecture | deployment, operability | proposed | architect, developer | non_functional | Design for bundle/download directory process. |
| `process_download_directory_for_tgz_lab_staging_configuration.md` | reference | deployment, configuration | active | operator, developer | non_functional | Environment-specific staging config reference. |
| `process_mysql_init.md` | runbook | database, deployment | active | operator, developer | non_functional | MySQL initialization procedure. |
| `process_quickstart_milestone2.md` | proposal | deployment, infrastructure | proposed | architect, developer, operator | non_functional | Milestone planning for quickstart evolution. |
| `process_one_shot_bundle_original_creation.md` | runbook | deployment, operability | active | operator, maintainer | non_functional | One-shot bundle build procedure. |
| `process_one_shot_bundle_original_creation_plus_backups.md` | runbook | deployment, operability | active | operator, maintainer | non_functional | Bundle build plus backup handling. |
| `DOCKER_COMPOSE_BEHAVIOR.md` | reference | infrastructure, configuration | active | developer, operator | non_functional | Docker Compose behavior reference under infrastructure. |
| `DOCKER_IMAGE_BUILD_CHANGE.md` | change | infrastructure, configuration | historical | developer, operator | non_functional | Docker image build change record. |
| `MIXING_HOSTVM_DOCKER_VERSIONS.md` | reference | infrastructure, operability | active | operator, developer | non_functional | Host/container versioning guidance. |
| `role_base.md` | reference | infrastructure, configuration | active | developer, operator | non_functional | Base role behavior/reference. |
| `role_upload_tests_vars.md` | reference | infrastructure, configuration | active | developer, qa | non_functional | Upload test role variable reference. |
| `ANSIBLE_FILE_INTERACTION.md` | reference | infrastructure, configuration | active | developer, operator | non_functional | Core IaC file interaction reference. |
| `docker_bind_mount_protection.md` | reference | infrastructure, operability | active | operator, developer | non_functional | Bind mount safety guidance. |
| `mysql_bind_mount_behavior.md` | reference | infrastructure, database | active | operator, developer | non_functional | MySQL bind mount behavior reference. |
| `mysql_backups_procedure.md` | runbook | database, operability | active | operator | non_functional | Backup procedure. |
| `database-health-check-testing.md` | debug | database, observability | active | qa, developer, operator | non_functional | Database health-check verification. |
| `DATABASE_LOAD_METHODS.md` | reference | database | active | developer, architect | mixed | Load methods and DB import patterns. |
| `DATABASE_LOAD_METHODS_SIMPLIFICATION.md` | proposal | database | proposed | developer, architect | mixed | Simplification proposal for data loading. |
| `database-import-process.md` | reference | database, operability | active | developer, operator | mixed | End-to-end import process reference. |
| `load_and_transform_mysql_initialization.md` | reference | database, deployment | active | developer, operator | non_functional | MySQL init/load reference. |
| `convert_legacy_database.md` | runbook | database | active | developer, operator | mixed | Legacy conversion workflow. |
| `convert_legacy_database_via_mysql_init.md` | runbook | database, deployment | active | developer, operator | mixed | Legacy conversion via init workflow. |
| `database_file_record_deletion.md` | howto | database, media | active | admin, developer | mixed | File-record deletion guidance. |
| `ADD_MEDIA_INFO_COLUMNS.md` | change | database, media | historical | developer | mixed | Schema/data change note. |
| `calculate_durationseconds_hash.md` | howto | database, media | active | developer, operator | mixed | Deriving duration/hash metadata. |
| `media_file_location_variables.md` | reference | media, configuration | active | developer, operator | non_functional | Media path/config variable reference. |
| `mediaFormatsSupported.md` | reference | media, upload | active | developer, admin | mixed | Supported media format reference. |
| `mime-types.md` | reference | upload, configuration | active | developer | non_functional | MIME type reference. |
| `CORE_UPLOAD_IMPLEMENTATION.md` | architecture | upload, api | active | developer, architect | mixed | Core upload implementation design. |
| `UPLOAD_OPTIONS.md` | reference | upload, deployment | active | developer, operator | mixed | Upload strategy/options comparison. |
| `uploadMediaByHash.md` | howto | upload, media | active | operator, admin, developer | mixed | Upload-by-hash usage guidance. |
| `change20250314_upload_media_jibe_php_path.md` | change | upload, configuration | historical | developer | mixed | Upload config alignment change note. |
| `addToDatabaseFeature.md` | feature | database, media | proposed | admin, developer, architect | functional | Add-to-database feature proposal. |
| `admin_data_import_45.md` | feature | database, admin | active | admin, developer | functional | Admin data-import workflow/features. |
| `feature_edit_database_interactively.md` | feature | database, admin | proposed | admin, developer | functional | Interactive DB editing feature. |
| `feature_iphone_video_zoom.md` | feature | media, performance | proposed | developer, architect | functional | iPhone video zoom feature plan. |
| `feature_future_strategy_licensing_communitypro_monetization.md` | proposal | operability | proposed | architect, maintainer, admin | non_functional | Business/licensing strategy proposal. |
| `feature_set.md` | architecture | infrastructure, operability | active | architect, developer, operator | non_functional | Capability/architecture inventory. |
| `media_google_analytics_feature_mod.md` | feature | observability, media | proposed | developer, admin | mixed | GA-related product/telemetry feature work. |
| `media_google_analytics_tracking.md` | reference | observability, media | active | developer, admin | non_functional | GA tracking reference. |
| `ADMIN_CLEAR_MEDIA.md` | howto | media, admin | active | admin, operator, developer | mixed | Clear-media admin operation guidance. |
| `audioVideoFullReducedLogic.md` | reference | media, configuration | active | developer | mixed | Media logic/behavior explanation. |
| `how_are_thumbnails_calculated.md` | reference | media, performance | active | developer | mixed | Thumbnail derivation explanation. |
| `pr_standalone_media_player.md` | change | media | historical | developer | functional | PR/implementation record for player. |
| `pr_delete_for_uploader.md` | proposal | media, authentication | proposed | developer, architect | mixed | Uploader deletion capability proposal. |
| `pr_delete_upload_iphone.md` | change | upload, media | historical | developer | functional | iPhone upload/delete PR record. |
| `pr_upload_testing.md` | reference | upload, qa | active | developer, qa | mixed | Upload testing plan/reference. |
| `PICKER_TRANSCODING_METHOD.md` | architecture | media, performance | active | developer, architect | mixed | Media picker/transcoding design. |
| `resizeRequestInstructions.md` | howto | infrastructure, deployment | active | operator | non_functional | VM/disk resize instructions. |
| `CONTENT_RANGE_CLOUDFLARE.md` | reference | streaming, deployment | active | developer, operator | non_functional | Cloudflare/content-range behavior reference. |
| `RANGE_REQUEST_FIX.md` | change | streaming, performance | historical | developer | non_functional | Range request fix record. |
| `problem_server_did_not_honor_range_request.md` | rca | streaming, performance | active | developer, operator | non_functional | RCA for range request issue. |
| `howdoesstreamingwork_implementation.md` | architecture | streaming, media | active | developer, architect | mixed | Streaming implementation explanation. |
| `chunkedfileconfiguration.md` | reference | upload, configuration | active | developer, operator | non_functional | Chunked file configuration reference. |
| `WHAT_IS_TUS.md` | reference | upload | active | developer, architect | mixed | TUS concept reference. |
| `tus_implementation_guide.md` | proposal | upload, deployment | proposed | developer, architect, operator | mixed | TUS implementation plan/guide. |
| `tus_implementation_iphone.md` | proposal | upload, media | proposed | developer, architect | functional | iPhone-oriented TUS implementation direction. |
| `tus_checks_no_log.md` | debug | upload, observability | active | developer, qa | non_functional | TUS troubleshooting/check guidance. |
| `to_remove_NetworkProgressUploadClient.md` | proposal | upload | superseded | developer | functional | Removal note for legacy upload client. |
| `rearch_prepare_video_cancel.md` | proposal | media, upload | proposed | developer, architect | functional | Video prepare/cancel rearchitecture proposal. |
| `VIDEO_PERFORMANCE_DEBUG.md` | debug | performance, media | active | developer, qa | non_functional | Performance troubleshooting. |
| `problem_cached_error_messages.md` | rca | security, operability | active | developer, operator | non_functional | Cloudflare cached-error RCA. |
| `problem_cert_does_not_trust_gighive_internal.md` | rca | security, deployment | active | developer, operator | non_functional | Certificate trust RCA. |
| `problem_donot_use_dot254_address.md` | rca | deployment, infrastructure | active | operator, developer | non_functional | Network/addressing problem note. |
| `problem_hsts_collision.md` | rca | security, deployment | active | developer, operator | non_functional | HSTS collision problem note. |
| `problem_sonarqube_phpsecurityS2083.md` | rca | security | active | developer | non_functional | Static-analysis security RCA. |
| `problem_virtio_ethtool_fix_2026019.md` | rca | infrastructure, performance | active | operator, developer | non_functional | Networking/performance problem note. |
| `APACHE_DIRECTIVE_MATCHING_ORDER.md` | reference | security, configuration | active | developer, operator | non_functional | Apache directive evaluation reference. |
| `cert_internal_no_warnings_guidance.md` | howto | security, deployment | active | operator, developer | non_functional | Internal cert guidance. |
| `cert_naming_consistency.md` | reference | security, configuration | active | developer, operator | non_functional | Certificate naming/reference. |
| `ai_intelligence_platform.md` | proposal | api, media | proposed | architect, developer | mixed | AI/intelligence platform direction. |
| `questions_for_librarianAsset_musicianSession_decision.md` | proposal | database, media | proposed | architect, developer | mixed | Open design questions. |
| `pr_librarianAsset_musicianEvent.md` | proposal | database, media | proposed | architect, developer | mixed | Remodel proposal. |
| `pr_librarianAsset_musicianEvent_implementation.md` | change | database, media | historical | developer, architect | mixed | Remodel implementation record. |
| `process_replace_existing_media.md` | runbook | media, operability | active | operator, admin, developer | mixed | Media replacement procedure. |
| `refactor_acls_on_restore_logs.md` | proposal | security, operability | proposed | developer, operator | non_functional | Refactor proposal around ACL/log restore. |
| `refactor_add_db_migrations_backup_before.md` | proposal | database, operability | proposed | developer, operator | non_functional | Migration backup refactor idea. |
| `refactor_admin_45_last_steps.md` | proposal | database, admin | proposed | developer, architect | functional | Admin 4/5 follow-up refactor. |
| `refactor_admin_page.md` | proposal | admin, api | proposed | developer, architect | functional | Admin page refactor proposal. |
| `refactor_bind_mount_guard_pattern.md` | proposal | infrastructure, operability | proposed | developer, operator | non_functional | Guard-pattern refactor proposal. |
| `refactor_convert_legacy_database_csv_python.md` | proposal | database | proposed | developer | mixed | DB conversion refactor proposal. |
| `refactor_db_database_admin_soft_deletes.md` | proposal | database, admin | proposed | developer, architect | functional | Soft-delete refactor proposal. |
| `refactor_db_ui_based_on_personas.md` | proposal | database, admin | proposed | developer, architect | functional | Persona-based UI refactor proposal. |
| `refactor_email_address.md` | proposal | authentication, configuration | proposed | developer | mixed | Email/address refactor proposal. |
| `refactor_fetch_media_lists.md` | proposal | media, api | proposed | developer | functional | Media-list fetch refactor proposal. |
| `refactor_hardening_sections45.md` | proposal | security, admin | proposed | developer, architect | non_functional | Hardening proposal for admin flows. |
| `refactor_quickstart_specific_template.md` | proposal | deployment, configuration | proposed | developer, operator | non_functional | Quickstart template refactor proposal. |
| `refactor_upload_tests.md` | proposal | upload, qa | proposed | developer, qa | mixed | Upload test refactor proposal. |
| `refactored_gighive_home_and_scripts_dir.md` | change | infrastructure, configuration | historical | developer, operator | non_functional | Path/layout refactor record. |
| `databasePhpColumnRules.txt` | reference | database, configuration | active | developer | non_functional | Non-markdown companion reference; excluded from table scope. |
| `process_download_directory_for_tgz_design.md` | architecture | deployment, operability | proposed | architect, developer | non_functional | Design for download packaging. |
| `user_provisioning.md` | howto | authentication, security | active | admin, operator | non_functional | User provisioning guidance. |
| `media_google_analytics_tracking.md` | reference | observability, media | active | developer, admin | non_functional | Tracking reference. |
| `pr_azure_blob_storage_integration.md` | proposal | media, infrastructure | proposed | architect, developer, operator | mixed | Azure blob integration proposal. |
| `comingsoon.html` | index | operability | proposed | admin, maintainer | non_functional | Non-markdown placeholder; excluded from table scope. |
| `codingChanges/20250926securityauthchanges.md` | change | security, authentication | historical | developer | non_functional | Coding change record. |
| `codingChanges/20250926uploaderConfDetail.md` | change | upload, configuration | historical | developer | mixed | Uploader config detail change record. |
| `codingChanges/20250926uploaderchanges.md` | change | upload, media | historical | developer | mixed | Uploader change record. |
| `codingChanges/featureChangedPasswordsPage.md` | change | authentication, admin | historical | developer | functional | Password page feature/change record. |
| `codingChanges/featureFixShareExtension.md` | change | upload, media | historical | developer | functional | Share extension fix/change record. |
| `codingChanges/featureGighiveDbOverlay.md` | feature | database, configuration | proposed | developer, architect | mixed | GigHive DB overlay feature note. |

## Known Ambiguities to Revisit Later

- Some `pr_*.md` files read more like `proposal` while others are really `change` records. They can be split later if desired.
- Several `process_*.md` docs may eventually separate into either `runbook` or `architecture` depending on whether they are normative procedures or design rationale.
- Some admin-focused docs could benefit from an eventual audience split between `admin` and `operator` usage.
- Some docs blend `database` and `media` concerns heavily because the current product spans both ingestion workflows and library behavior.
- `aspect` is intentionally conservative here; many documents are marked `mixed` where both behavior and operational qualities are central.

## Suggested Next Derivatives

This document can be used to generate:

- a docs landing page grouped by `type`
- a topic-by-type matrix grouped by `concern`
- reading paths by `audience`
- a timeline view using `lifecycle`
- Mermaid maps of the docs corpus
