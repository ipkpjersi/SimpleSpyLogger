# TODO

## Restore Reddit history from the account data export

The 2026-10-07 database wipe (tests ran `RefreshDatabase` against the real DB)
dropped every Reddit row. The scraper can only re-fetch recent comments, so the
older history has to come back from Reddit's data export.

- [ ] Wait for the export requested on 2026-11-02 (reddit.com/settings/data-request,
      "All time"). Reddit says it can take up to 30 days, so check back around
      2026-12-02.
- [ ] Write `sources/reddit/import_export.php`, modeled on
      `sources/twitter/import_archive.php`: read `comments.csv` (and `posts.csv`)
      from the export zip and upsert them as `source='reddit'`.
- [ ] Use the same `external_id` format the scraper writes
      (`reddit.com:t1_<id>` for comments) so rows already re-scraped since the
      wipe merge instead of duplicating.
- [ ] Back up first with the backup script, run the import, then compare counts.
      `sources/import.log` logged 2773 Reddit inserts between 2026-05-29 and the
      wipe, vs 601 rows after it.
