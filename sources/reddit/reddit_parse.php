<?php

declare(strict_types=1);

/**
 * SimpleSpyLogger - Reddit comment parsers used by download.php.
 *
 * Two response formats map to one normalized comment shape, so the scraper's
 * insert/edit logic does not care where a comment came from:
 *
 *   json  /user/<name>/comments.json (Reddit's listing JSON). Most complete:
 *         raw markdown body and the parent comment id.
 *   html  old.reddit.com/user/<name>/comments/ (the old-Reddit listing page),
 *         the fallback for when the .json endpoints go away. The body is only
 *         available rendered, so it is converted back to markdown on a
 *         best-effort basis and will not always match the JSON body exactly.
 *         The listing page does not show the parent comment, so parent_id is
 *         always null, and controversiality is not shown either.
 *
 * Normalized comment keys (named after Reddit's JSON fields):
 *   id, author, author_fullname, subreddit, subreddit_id, subreddit_type,
 *   link_id, link_title, link_permalink, permalink, parent_id, body,
 *   created_utc (int|null), edited (int|null), score, controversiality,
 *   distinguished, stickied, content_source ("json" or "html").
 */

/**
 * Parse a comments.json listing. Returns null when the body is not the
 * expected listing shape (e.g. an HTML error or login page served with 200).
 *
 * @return array<int, array<string, mixed>>|null
 */
function reddit_parse_json_listing(string $body): ?array
{
    $data = json_decode($body, true);
    if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
        return null;
    }
    $children = $data['data']['children'] ?? [];
    if (! is_array($children)) {
        return null;
    }

    $comments = [];
    foreach ($children as $child) {
        if (($child['kind'] ?? null) !== 't1' || empty($child['data']['id'])) {
            continue;
        }
        $c = $child['data'];
        $comments[] = [
            'id' => (string) $c['id'],
            'author' => (string) ($c['author'] ?? ''),
            'author_fullname' => $c['author_fullname'] ?? null,
            'subreddit' => $c['subreddit'] ?? null,
            'subreddit_id' => $c['subreddit_id'] ?? null,
            'subreddit_type' => $c['subreddit_type'] ?? null,
            'link_id' => $c['link_id'] ?? null,
            'link_title' => $c['link_title'] ?? null,
            'link_permalink' => $c['link_permalink'] ?? null,
            'permalink' => $c['permalink'] ?? null,
            'parent_id' => $c['parent_id'] ?? null,
            'body' => $c['body'] ?? null,
            'created_utc' => isset($c['created_utc']) && is_numeric($c['created_utc']) ? (int) $c['created_utc'] : null,
            // Reddit sends false for never-edited comments, else an epoch.
            'edited' => isset($c['edited']) && is_numeric($c['edited']) ? (int) $c['edited'] : null,
            'score' => $c['score'] ?? null,
            'controversiality' => $c['controversiality'] ?? null,
            'distinguished' => $c['distinguished'] ?? null,
            'stickied' => $c['stickied'] ?? null,
            'content_source' => 'json',
        ];
    }

    return $comments;
}

/**
 * Parse an old-Reddit user comments listing page. Returns null when the page
 * has no listing at all (login wall, block page, "page not found"), which is
 * different from a listing with zero comments (returns []).
 *
 * @return array<int, array<string, mixed>>|null
 */
function reddit_parse_html_listing(string $html): ?array
{
    $doc = new DOMDocument();
    $prevErrors = libxml_use_internal_errors(true);
    // The XML prolog makes libxml read the page as UTF-8 instead of Latin-1.
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrors);
    if (! $loaded) {
        return null;
    }

    $xp = new DOMXPath($doc);
    $siteTable = $xp->query("//div[@id='siteTable']")->item(0);
    if (! $siteTable instanceof DOMElement) {
        return null;
    }

    $comments = [];
    foreach ($xp->query("./div[@data-type='comment']", $siteTable) as $thing) {
        if (! $thing instanceof DOMElement) {
            continue;
        }
        $fullname = $thing->getAttribute('data-fullname');
        if (strpos($fullname, 't1_') !== 0) {
            continue;
        }

        $permalink = $thing->getAttribute('data-permalink');
        $linkId = null;
        if (preg_match('#/comments/([a-z0-9]+)/#i', $permalink, $m)) {
            $linkId = 't3_'.$m[1];
        }

        $title = $xp->query("./p[contains(concat(' ', normalize-space(@class), ' '), ' parent ')]/a[contains(concat(' ', normalize-space(@class), ' '), ' title ')]", $thing)->item(0);
        // The title links to the post's target (an external URL or gallery for
        // link posts); the "full comments" button is the post's own permalink.
        $fullComments = $xp->query(".//a[@data-event-action='full_comments']", $thing)->item(0);
        $entry = $xp->query("./div[contains(concat(' ', normalize-space(@class), ' '), ' entry ')]", $thing)->item(0);
        $tagline = $entry instanceof DOMElement
            ? $xp->query("./p[contains(concat(' ', normalize-space(@class), ' '), ' tagline ')]", $entry)->item(0)
            : null;
        $md = $xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' usertext-body ')]/div[contains(concat(' ', normalize-space(@class), ' '), ' md ')]", $thing)->item(0);

        $created = null;
        $edited = null;
        $score = null;
        $distinguished = null;
        if ($tagline instanceof DOMElement) {
            $created = reddit_html_time($xp, $tagline, 'live-timestamp');
            $edited = reddit_html_time($xp, $tagline, 'edited-timestamp');
            // Three score spans are rendered (dislikes, unvoted, likes) so the
            // page can flip between them when the viewer votes. The one that
            // matches the viewing account's current vote, shown as a class on
            // the entry div, is the real score. No spans when the subreddit
            // hides scores.
            $vote = 'unvoted';
            foreach (['likes', 'dislikes'] as $state) {
                if ($entry instanceof DOMElement && reddit_html_has_class($entry, $state)) {
                    $vote = $state;
                }
            }
            $scoreEl = $xp->query("./span[contains(concat(' ', normalize-space(@class), ' '), ' score ') and contains(concat(' ', normalize-space(@class), ' '), ' $vote ')]", $tagline)->item(0);
            if ($scoreEl instanceof DOMElement && preg_match('/^-?\d+$/', $scoreEl->getAttribute('title'))) {
                $score = (int) $scoreEl->getAttribute('title');
            }
            $authorEl = $xp->query("./a[contains(concat(' ', normalize-space(@class), ' '), ' author ')]", $tagline)->item(0);
            if ($authorEl instanceof DOMElement) {
                foreach (['admin', 'moderator'] as $role) {
                    if (reddit_html_has_class($authorEl, $role)) {
                        $distinguished = $role;
                        break;
                    }
                }
            }
        }

        $linkPermalink = null;
        if ($fullComments instanceof DOMElement) {
            $path = (string) parse_url($fullComments->getAttribute('href'), PHP_URL_PATH);
            // Match the JSON's absolute www form.
            $linkPermalink = $path !== '' ? 'https://www.reddit.com'.$path : null;
        }

        $comments[] = [
            'id' => substr($fullname, 3),
            'author' => $thing->getAttribute('data-author'),
            'author_fullname' => $thing->getAttribute('data-author-fullname') ?: null,
            'subreddit' => $thing->getAttribute('data-subreddit') ?: null,
            'subreddit_id' => $thing->getAttribute('data-subreddit-fullname') ?: null,
            'subreddit_type' => $thing->getAttribute('data-subreddit-type') ?: null,
            'link_id' => $linkId,
            'link_title' => $title instanceof DOMElement ? $title->textContent : null,
            'link_permalink' => $linkPermalink,
            'permalink' => $permalink ?: null,
            'parent_id' => null,
            'body' => $md instanceof DOMElement ? reddit_html_to_markdown($md) : null,
            'created_utc' => $created,
            'edited' => $edited,
            'score' => $score,
            'controversiality' => null,
            'distinguished' => $distinguished,
            'stickied' => reddit_html_has_class($thing, 'stickied'),
            'content_source' => 'html',
        ];
    }

    return $comments;
}

function reddit_html_has_class(DOMElement $el, string $class): bool
{
    return in_array($class, preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [], true);
}

function reddit_html_time(DOMXPath $xp, DOMElement $tagline, string $class): ?int
{
    $el = $xp->query("./time[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]", $tagline)->item(0);
    if (! $el instanceof DOMElement) {
        return null;
    }
    $ts = strtotime($el->getAttribute('datetime'));

    return $ts === false ? null : $ts;
}

/**
 * Convert a rendered Reddit comment body (the div.md element) back to Reddit
 * markdown. Best effort: covers what Reddit's renderer emits (paragraphs,
 * emphasis, links, code, quotes, lists, headings, tables, spoilers,
 * superscript) but cannot recover the exact original source.
 */
function reddit_html_to_markdown(DOMNode $node): string
{
    return trim(reddit_md_blocks($node));
}

const REDDIT_MD_BLOCK_TAGS = ['p', 'pre', 'blockquote', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'table', 'div'];

function reddit_md_blocks(DOMNode $parent): string
{
    $blocks = [];
    $inline = '';
    foreach ($parent->childNodes as $child) {
        $tag = $child instanceof DOMElement ? strtolower($child->tagName) : '';
        if (! in_array($tag, REDDIT_MD_BLOCK_TAGS, true)) {
            $inline .= reddit_md_inline($child);
            continue;
        }
        if (trim($inline) !== '') {
            $blocks[] = trim($inline);
        }
        $inline = '';
        $block = reddit_md_block($child, $tag);
        if ($block !== '') {
            $blocks[] = $block;
        }
    }
    if (trim($inline) !== '') {
        $blocks[] = trim($inline);
    }

    return implode("\n\n", $blocks);
}

function reddit_md_block(DOMElement $el, string $tag): string
{
    switch ($tag) {
        case 'p':
            return trim(reddit_md_inline_children($el));
        case 'pre':
            $lines = explode("\n", rtrim($el->textContent, "\n"));

            return implode("\n", array_map(static fn (string $l): string => '    '.$l, $lines));
        case 'blockquote':
            $inner = reddit_md_blocks($el);

            return implode("\n", array_map(static fn (string $l): string => $l === '' ? '>' : '> '.$l, explode("\n", $inner)));
        case 'ul':
        case 'ol':
            $items = [];
            $n = (int) ($el->getAttribute('start') ?: 1);
            foreach ($el->childNodes as $li) {
                if (! $li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                    continue;
                }
                $marker = $tag === 'ol' ? ($n++).'. ' : '* ';
                $lines = explode("\n", reddit_md_blocks($li));
                $first = array_shift($lines);
                $rest = array_map(static fn (string $l): string => $l === '' ? '' : '    '.$l, $lines);
                $items[] = $marker.implode("\n", array_merge([$first], $rest));
            }

            return implode("\n", $items);
        case 'hr':
            return '***';
        case 'table':
            return reddit_md_table($el);
        case 'div':
            return reddit_md_blocks($el);
        default:
            // h1-h6
            return str_repeat('#', (int) substr($tag, 1)).' '.trim(reddit_md_inline_children($el));
    }
}

function reddit_md_table(DOMElement $table): string
{
    $rows = [];
    $headerCells = 0;
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $cells = [];
        $isHeader = false;
        foreach ($tr->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                $isHeader = $isHeader || strtolower($cell->tagName) === 'th';
                $cells[] = str_replace('|', '\\|', trim(reddit_md_inline_children($cell)));
            }
        }
        $rows[] = '|'.implode('|', $cells).'|';
        if ($isHeader && $headerCells === 0) {
            $headerCells = count($cells);
            $rows[] = '|'.implode('|', array_fill(0, max(1, $headerCells), ':-')).'|';
        }
    }

    return implode("\n", $rows);
}

function reddit_md_inline_children(DOMNode $parent): string
{
    $out = '';
    foreach ($parent->childNodes as $child) {
        $out .= reddit_md_inline($child);
    }

    return $out;
}

function reddit_md_inline(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return $node->textContent;
    }
    if (! $node instanceof DOMElement) {
        return '';
    }
    $inner = reddit_md_inline_children($node);
    switch (strtolower($node->tagName)) {
        case 'strong':
        case 'b':
            return '**'.$inner.'**';
        case 'em':
        case 'i':
            return '*'.$inner.'*';
        case 'del':
        case 's':
            return '~~'.$inner.'~~';
        case 'code':
            return '`'.$node->textContent.'`';
        case 'sup':
            return preg_match('/\s/', $inner) ? '^('.$inner.')' : '^'.$inner;
        case 'br':
            // Reddit markdown's hard line break is two trailing spaces.
            return "  \n";
        case 'span':
            return reddit_html_has_class($node, 'md-spoiler-text') ? '>!'.$inner.'!<' : $inner;
        case 'a':
            $href = $node->getAttribute('href');
            // Autolinked URLs and r/ or u/ mentions were plain text in the
            // source, so emit the text alone when it is the link target.
            if ($href === '' || reddit_md_same_target($inner, $href)) {
                return $inner;
            }

            return '['.$inner.']('.$href.')';
        default:
            return $inner;
    }
}

function reddit_md_same_target(string $text, string $href): bool
{
    $norm = static fn (string $s): string => rtrim(preg_replace('#^(https?://(www\.|old\.)?reddit\.com)?/?#i', '', $s) ?? $s, '/');

    return $text === $href || $norm($text) === $norm($href);
}
