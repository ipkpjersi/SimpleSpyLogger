# TODO

## Restore Reddit history from the account data export

The 2026-10-07 database wipe (tests ran `RefreshDatabase` against the real DB)
dropped every Reddit row. The scraper can only re-fetch recent comments, so the
older history has to come back from Reddit's data export.

- [x] Wait for the export requested on 2026-11-02 (reddit.com/settings/data-request,
      "All time"). Arrived 2026-11-17; the zips live in `sources/reddit/` (gitignored).
- [x] Write `sources/reddit/import_export.php`, modeled on
      `sources/twitter/import_archive.php`: comments, posts, inbox PMs and chat.
- [x] Use the same `external_id` format the scraper writes
      (`reddit.com:t1_<id>` for comments) so rows already re-scraped since the
      wipe merge instead of duplicating.
- [x] Back up first with the backup script, run the import, then compare counts.
      2026-11-17: 67,394 rows imported on top of 921 scraped; all 609 overlapping
      comments matched, and the 312 rows newer than the export were left alone.
      2026-11-18: exports from two more (unscraped) accounts added 274 and
      1,672 rows.
- [x] Fill in the post titles the export lacks: `sources/reddit/backfill_titles.php`.

## Reddit scraper gaps found while comparing against the export

- [ ] `download.php` only reads `/comments`, so new posts are never captured.
      Add the user's `/submitted` listing.
- [ ] Reddit chat is not captured by anything. A later data export can be
      re-imported with `import_export.php` to top it up (re-runs are idempotent).
- [ ] One 100-comment page covers about 5 days of downtime at the busier
      account's rate. Paginating with `after=` (up to Reddit's ~1000 cap) would
      cover weeks.
- [ ] A handful of comments (probably mod-removed or filtered) never appear in
      the public listing; only the export has them.
