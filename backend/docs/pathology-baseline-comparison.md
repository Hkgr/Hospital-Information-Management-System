# PR #26: preserved-database baseline comparison

Both runs used PHP 8.4.14, PHPUnit 12.5.35, mysql/MariaDB 10.11.18 and
`blood_bank_cities_testing` on `127.0.0.1:13416`. The safety guard passed before each.
Runs were serial, with no live fixture creation between them. Transactional tests
preserved retained rows. No reset, truncation, fresh migration or data repair was used.

Base was checked out into a detached worktree with its own copied vendor directory,
bootstrap, configuration and application code, using the same ignored testing settings.
The feature worktree and its uncommitted correction were not switched/reset.

Base SHA: `d955be79c7eb184727924c0ddeec5d7fa5876dee`.
Head: corrected `feature/pathology-diagnostic-workflow`; the exact delivery SHA is
recorded in PR #26 (this document is part of that commit).

The identical command in each backend directory was:

```sh
php artisan test-db:check --connect --env=testing
php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php tests/Feature/ClinicApiTest.php tests/Feature/DoctorApiTest.php tests/Feature/DirectoryLifecycleTest.php --colors=never --log-junit storage/framework/testing/pathology-comparison.xml
```

Only log artifact basenames differ between runs (`pathology-baseline` / `pathology-head`).

| Run | Tests | Assertions | Failures | Skips |
|---|---:|---:|---:|---:|
| Base | 54 | 1,993 | 11 | 0 |
| Corrected head | 54 | 2,006 | 10 | 0 |

The ten shared failures have identical test names and identical expected/actual count
messages in both XML reports. Each message has the form:
`Failed asserting that table [TABLE] matches expected entries count of E. Entries found: A.`

| Suite / test | Table | Expected (both) | Actual base | Actual head |
|---|---|---:|---:|---:|
| ClinicApiTest::test_doctor_eligibility_fail_closed_and_validation | clinics | 0 | 116 | 116 |
| ClinicApiTest::test_history_same_day_reopen_and_stale_version | clinic_staff | 1 | 103 | 103 |
| ClinicApiTest::test_patients_count_distinct_complete_visits_only_and_delete_protection | visits | 4 | 697 | 697 |
| DoctorApiTest::test_crud_global_unique_code_specialties_and_unrelated_fields_survive | users | 1 | 159 | 159 |
| DoctorApiTest::test_bidirectional_links_history_versions_hidden_and_future_periods | clinic_staff | 2 | 104 | 104 |
| DoctorApiTest::test_counts_are_distinct_attending_complete_not_clinic_patients_and_references_block_delete | visit_procedures | 1 | 617 | 617 |
| DoctorApiTest::test_explicit_grant_is_previewed_idempotent_and_never_guessed_from_role_name | facility_user_roles | 1 | 223 | 223 |
| DirectoryLifecycleTest::test_inactive_clinic_cannot_receive_a_new_doctor_link | clinic_staff | 0 | 102 | 102 |
| DirectoryLifecycleTest::test_explicit_link_after_restore_does_not_reopen_cancelled_future_period (data set #0) | clinic_staff | 2 | 104 | 104 |
| DirectoryLifecycleTest::test_explicit_link_after_restore_does_not_reopen_cancelled_future_period (data set #1) | clinic_staff | 2 | 104 | 104 |

The base-only eleventh failure is
`DirectoryLifecycleTest::test_reference_inventory_covers_all_current_foreign_keys`:
`blood_bank_cities_testing.visit_diagnostic_assessments reference must be classified` /
`Failed asserting that an array contains 'clinic_id'.`

That is an explicit limitation of running the old application against the same retained
post-Phase-2 schema: the old reference inventory does not know the new tables. The head
does classify those FKs and passes this test. We did not drop populated new tables or
change the base test to hide this difference. The head has no additional/different
failure; the ten whole-table-count failures are baseline-equivalent existing failures,
not a claim that the broader suite is green. The unrelated assertions were not weakened.
