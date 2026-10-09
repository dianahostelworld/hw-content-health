=== Hostelworld Content Health ===
Version: 1.16.0

Finds blog articles worth refreshing and articles with no search traffic, using
Google Search Console data. Menu: Content health.

== Reports ==

Dashboard
  The blog's Search Console performance for the last 7, 28 or 90 days,
  compared with the period before: clicks, impressions, CTR and average
  position; clicks and impressions per day (previous period dashed); the top
  20 pages and top 20 search queries with their change; and the 10 biggest
  gains and 10 biggest drops in clicks. Fetched live and cached for 12 hours;
  "Refresh" fetches again.

Language filter
  The Dashboard, Content planner (new in 1.8)
  Built from the searches the English blog appeared for in the search data
  window (up to 50,000, fetched at each sync). Each search is matched to the
  most specific destination it names (a city over its country) and to a
  travel topic: festivals and events, where to stay, hostels, itinerary and
  how long, best time and weather, safety, budget and costs, getting there
  and around, day trips, nightlife, food and drink, things to do, beaches and
  nature, culture and history, solo travel, practical tips, backpacking and
  routes. Searches that fit no topic are grouped by their other words.
  Searches for "hostelworld" are left out. Place names that are also ordinary
  words (Nice, Split, Bath…) only count when the place above them is in the
  search too ("nice france").
  - Gaps: a destination and topic with impressions (at least 100 by default)
    but no English article about that destination whose title covers the
    topic. The other filter shows topics an article covers but ranks for on
    page 3 or lower. Seasonal destinations show their peak month and a
    publish-by date (the refresh window's lead time before the season).
  - Destinations: every destination with a hub page or searches: hub status,
    English articles ("Thin" at two or fewer), impressions, the share on page
    2 or lower, and gaps.
  - Searches with no destination: searches naming no destination, ranking
    below the first page, that match no article title.
  - Plan: gaps added from the reports, or your own ideas, with a status
    (Idea, Briefed, Writing, Published) and a publish-by month. "Create draft"
    makes the WordPress draft with the destination's tags, and the brief
    (topic, date, searches to cover) shows in the editor panel while it is
    written. The status follows the draft once it exists.
  Search Console only reports searches the blog already appears for, so a gap
  always has real impressions behind it, but demand for destinations or
  topics the blog has never appeared for is not visible here; that needs
  keyword data from outside Search Console, such as Ahrefs.

AI Overviews (new in 1.9)
  Articles seen about as often as a year ago and ranking about as well, but
  clicked much less: the pattern an AI Overview leaves. The last 90 days are
  compared with the same 90 days last year (52 weeks back, so weekdays line
  up and seasons cancel out). Listed when impressions are at least 80% of
  last year's, position is no more than 1.5 places worse, and the click rate
  fell by at least 30% (adjustable), with at least 1,000 impressions and 20
  clicks last year. "Clicks lost" is this period's impressions at last year's
  click rate, minus this period's clicks. Search Console does not report AI
  Overviews, so this is an inference: a new People also ask box, video
  results, more ads or a title change leave the same pattern.

Refresh brief (new in 1.9)
  One page per article, opened from Worth refreshing, Seasonal planner,
  Question coverage, AI Overviews and the editor panel:
  performance and trend, why to refresh now (season, falling clicks, likely
  AI Overview, past years in the title, missing questions, broken links,
  overlap), the searches it ranks for (marking
  those no heading reflects), questions to answer, past years mentioned,
  on-page issues, links to fix and to add, and overlapping articles. A plain-text copy pastes into a
  ticket. "Add to the content plan" adds it as a refresh, marked done once
  the article is updated.

Tracked URLs, Quick wins and Worth refreshing
  have a language picker (with WPML or Polylang). Search Console data is
  split by the language folder in the URL (/blog/es/…); articles by their
  language in WPML or Polylang. The choice is remembered per person.

Tracked URLs
  Named groups of URLs (full URLs or paths, up to 200 per group) to follow
  together, for the last 7, 28, 90 or 180 days against the period before:
  totals for the group, a clicks chart for the whole group, and per URL:
  clicks, click trend, impressions, CTR and position, each with its change.
  URLs match whether or not they have www, https or a trailing slash. Cached
  for 12 hours; "Refresh" fetches again.

Quick wins
  Last 28 days, two lists:
  - Pages with low clicks for their position: pages ranking in the top five
    (with at least 500 impressions by default) that get under half the
    click-through rate typical for their position. Estimated missed clicks
    compares them with a broad industry benchmark, so treat it as a guide to
    what to fix first. The SEO title can be edited inline.
  - Striking distance: searches where a page ranks on average between
    positions 8 and 20, with at least 200 impressions by default, most
    impressions first.
  Cached for 12 hours; "Refresh" fetches again.

Question coverage (new in 1.7)
  English articles only. Question-style searches each article already appears
  for (at least 20 impressions by default) that none of its headings or FAQ
  questions match, articles with the most unmatched impressions first.
  - A search is a question if it starts with a question word (how, what, is,
    can, should…) or contains "best time to", "worth it" or "worth visiting".
  - It is matched when one heading or FAQ question contains at least two
    thirds of its meaningful words. The article's own destination is set
    aside, so "how many days in porto" is matched in a Porto article by "How
    many days do you need?". A few close variants count as the same word
    (safe/safety, cost/price/expensive/cheap, when/time/month, long/days).
  - Recognised: h2 to h6, the Details block, the Yoast and Rank Math FAQ
    blocks, elements whose class names an FAQ question or accordion title,
    bold text ending in "?", questions stored in block settings, and any CSS
    class set in Settings > Question coverage.
  - "Not found" does not mean unanswered: the answer may be in a paragraph.
  Uses the searches stored at each sync (up to 50,000 page-and-search rows,
  the ones with the most clicks), so articles with little traffic may have
  none. "Covered already" hides an article for six months.

Worth refreshing
  Articles with at least N clicks in the data window that have not been updated
  for over N months, most clicks first. The clicks trend compares the last 90
  days with the 90 days before, to show which are already slipping.
  Actions: Edit, Queue for refresh (a badge so nobody picks it up twice),
  Dismiss (hidden for six months).

Seasonal planner (new in 1.7)
  Clicks per article per calendar month, stored at each sync. Past months never
  change, so after the first sync only the newest month is fetched. A first
  sync that runs out of time stops and continues next time; "Fetch the
  remaining months" finishes it straight away.
  - An article's season is the run of months where it gets at least 25% more
    clicks than its average month. It counts as seasonal with at least 200
    clicks over the months stored and a best month at least 1.5 times its
    average month (both adjustable), and at least 10 months of data since it
    was published (months before publication are left out, not counted as
    zero).
  - Refresh soon: articles whose refresh window (opening 8 weeks and due 6
    weeks before the season starts, adjustable) is open, overdue ("Late") or
    opens within four weeks. Articles updated since shortly before the window
    opened count as done.
  - All seasonal articles: the whole year.
  - Destinations by month: English articles added up per destination, as a
    calendar, for timing commissions as well as refreshes.
  Search Console keeps 16 months, so most calendar months have been seen only
  once: treat a season as a strong hint, not a certainty.
  Actions: Edit, Queue for refresh, Not seasonal (hidden for six months).

Refresh impact
  Every article that was queued in Worth refreshing and then updated (the
  first update after queuing counts as the refresh, detected automatically),
  or marked as refreshed with "Mark refreshed". Each is compared on daily
  averages: the 28 days before the refresh (adjustable) against the same
  number of days after it, or as many as have passed. Results appear once
  there are 14 days of data after the refresh. The summary shows how many
  refreshes gained clicks and the median change.

Candidates to prune
  English articles only (detected through WPML or Polylang; with neither
  active, all articles count as English). Articles published over N months ago with no clicks and at most N impressions
  in the data window. Articles Search Console has no data for count as zero.
  Actions: Redirect (301 to a URL or post ID on this site, then moved to draft),
  Unpublish only (moved to draft, URL returns 404), Keep (hidden for six months).
  Nothing is ever deleted.

Overlapping articles
  English articles only, so translations are never compared with each other.
  Articles about different destinations (e.g. "Best bars in Porto" and "Best
  bars in Krakow") are never grouped. Destinations come from the tags the
  destination hubs use, matched against each article's tags and title.
  Articles competing for the same searches, or with near-identical titles,
  grouped together (A overlaps B and B overlaps C makes one group of three).
  Two signals:
  - Shared searches: the share of the smaller article's impressions that come
    from search queries the other article also appears for (last 90 days by
    default). Queries that more than 15 articles appear for are ignored as
    too generic.
  - Similar titles: share of meaningful words in common, ignoring filler words,
    numbers and years. Catches duplicates with too little traffic to compare.
  Groups are labelled Likely duplicates (60%+ shared searches or 85%+ alike
  titles), Strong overlap (30%+ / 70%+) or Related, strongest first.
  Each group shows every article's clicks, impressions, position and last
  update, and the top shared searches with each article's position for them.
  Actions:
  - Keep one (the one with the most clicks is suggested) and redirect the
    ticked others to it with a 301; they move to draft.
  - Edit the SEO title inline (Yoast or Rank Math title if active, otherwise
    the post title).
  - "These are fine as they are": the group stops appearing.

Out of date
  English articles only.
  - Past years in titles: the title, SEO title or URL names a year before
    this one. Edit the post title or SEO title inline, or mark it "Still
    correct" to hide it for six months. URLs are only flagged (changing one
    needs a redirect).
  - Event articles not updated: articles with an event tag (events, festivals
    by default) not updated for 6 months.
  Most clicks first.

Internal links
  English articles that no other English article or page links to, or only
  one or two do, counted from links written in the content (menus, sidebars and the
  hubs' automatic lists are not counted). For each, up to three articles
  about the same destination that do not link to it yet. Recounted hourly.

Broken links (new in 1.7)
  - Internal links: links written into English articles to a blog page that no
    longer works as linked: articles merged or pruned (redirected, with the
    number of hops), unpublished articles, removed tag or category pages, and
    other blog pages returning an error. "Update link" points a redirected
    link straight at the final article, or a removed tag page's link at its
    destination hub; it keeps any #anchor and can be undone from the Log as
    long as the article has not been edited since (otherwise use the
    article's revisions). Recounted hourly.
  - Outbound links: links to other sites, including the rest of
    hostelworld.com, that returned an error or no response. Checked in the
    background, about 60 an hour, each rechecked after 30 days (adjustable),
    so a first full pass takes a while. Sites that refuse automated checks
    (LinkedIn, Instagram, Facebook, X, TikTok, Pinterest, and any answering
    401, 403 or 429) are listed separately, not as broken.
  - 404s: blog addresses visitors reached that returned a 404, most visits
    first, with the last referring page. Files (images, scripts) and admin
    addresses are not logged; addresses visited only once or twice and not
    for 60 days are cleared. Each has a suggested redirect where there is a
    good match: an article with the same address ending, the destination hub
    for a removed tag page, or the closest article by the words in its address
    (at least half matching). Redirect (301) or Ignore; both can be undone.
  - Redirect chains: this plugin's redirects that pass through another
    redirect, or land on an unpublished article. "Point straight to the final
    page" fixes a chain and can be undone.
  Only redirects made by this plugin are known to it; redirects from another
  plugin or the server show as working pages.

On-page
  English articles with: no meta description (write one inline, saved to
  Yoast or Rank Math), the same SEO title as another article (edit inline),
  no featured image, under 500 words (adjustable), or noindex set.
  Checked hourly.

Tags & hubs
  English articles only.
  - No destination tag: articles with none of the tags the destination hubs
    use, with a one-click "Add" for any destination named in the title.
  - Duplicate tags: the same name ignoring case, accents and punctuation, or
    the same slug with -2 to -9 added. Merge moves the articles onto the tag
    you keep; the emptied tag is left in place.
  - Tags used once (or not at all).
  - Hubs with few articles: hubs whose tag is on fewer than 5 English
    articles.

Editor check (new in 1.7)
  A "Content health" panel in the post editor's sidebar (block editor only).
  Checked as the title or tags change, and with "Check again":
  - Similar articles already published (English only, never a different
    destination): titles at least half alike; articles that already get
    impressions from searches matching the title; pairs already flagged in
    Overlapping articles. Strong matches (titles as alike as the Overlapping
    articles threshold, 1,000+ matching impressions, or an existing pair) also
    appear as a warning in the publish confirmation step.
  - Link to these from this article: the destination hub, then the
    destination's articles with the most clicks, leaving out any already
    linked in the content.
  - Link to this article from these: articles about the same destination that
    do not link to it yet.
  - For a published article: its clicks, impressions, position and 90-day
    trend, top searches, season and refresh date, and question searches not
    yet in a heading or FAQ, checked against the content as it is being
    edited.
  Destinations come from the article's tags or the title.

Log
  Every action, who did it and when. Undo restores the article's status and
  removes any redirect the action created.

== Setup ==

There are two ways to connect Search Console. Use "Connect with Google" if you
can see the property but are not an owner of it (owners are the only people
who can add a service account).

--- Option A: Connect with Google (your own account) ---

1. Go to console.cloud.google.com, signed in with your Hostelworld Google
   account. Create a project (or use an existing one), then open
   "APIs & Services" > "Library" and enable the "Google Search Console API".
2. "APIs & Services" > "OAuth consent screen" (called "Google Auth Platform"
   in newer consoles): choose User type "Internal", give the app a name
   (e.g. "Blog content health") and a support email, and save.
   Internal matters: an External app left in Testing mode stops working after
   7 days and has to be reconnected.
3. "APIs & Services" > "Credentials" > "Create credentials" > "OAuth client ID":
   - Application type: Web application
   - Authorised redirect URIs: paste the Redirect URI shown in
     Content health > Settings (it ends in admin-post.php?action=hwch_oauth_callback)
   Create it, and copy the client ID and client secret.
4. In WordPress, Content health > Settings:
   - Sign in with: My Google account
   - Property: exactly as Search Console shows it, e.g. https://www.hostelworld.com/blog/
   - OAuth client ID and secret: paste them, and Save.
5. Click "Connect with Google" and sign in with the account that can see the
   property in Search Console. Then click "Sync now".

The connection stays in place; the weekly sync uses it with nobody logged in.
If the account loses access to the property, or someone disconnects it, the
sync stops and the error says so; click Connect with Google to sign in again.

--- Option B: Service account ---

1. In Google Cloud, enable the "Google Search Console API" and create a
   service account. Create a JSON key for it.
2. A property owner adds the service account's email in Search Console under
   Settings > Users and permissions (Restricted is enough).
3. In Content health > Settings, choose "A service account", paste the JSON
   key (or define HWCH_GSC_KEY_FILE in wp-config.php), set the property, Save,
   and click Sync now.

== Notes ==

- Search Console data lags about three days; ranges end three days ago.
- The overlap report uses up to 50,000 page-and-query rows per sync (the ones
  with the most clicks). If that part of the sync fails, the page figures are
  still updated and the error is shown.
- Until the first successful sync, the reports are hidden, because every
  article would otherwise look like it had no traffic.
- A failed sync keeps the previous data and shows the error at the top.
- Redirects are served by this plugin. If an article is later restored from
  the Log, its redirect is removed.
- Requires the PHP OpenSSL extension (standard on almost all hosts).
- 1.7 adds four tables (searches, monthly figures, 404s, link checks) and an
  hourly background job for link checks; both are set up automatically when
  the plugin is updated. The first sync after updating takes longer, as it
  fetches up to 15 months of monthly figures.

== Changelog ==

1.16.0
- Health score out of 100 at the top of To-do and as a Dashboard tile.
  Four areas, each the share of published English articles without that
  problem: Freshness 35%, On-page basics 25%, Links 20%, Clean-up 20%.
  Saved weekly, with the change since last week and a 12-week trend.

1.15.0
- Broken links: Remove link on each broken internal and outbound link, and
  Remove all broken links with a preview first. The linked words stay;
  each article can be undone from the Log.
- New Retire articles page (Clean up): find published articles with a
  phrase in the title or address ("best hostels in" to start) and redirect
  the ticked ones, each to its destination hub or the blog homepage, or any
  address you type.

1.14.0
- New Import page (Data > Import): download a template of missing meta
  descriptions, missing SEO titles or duplicate titles, fill it in and upload
  it. A preview shows what will change; existing values are only replaced if
  you tick the box. Every change is in the Log, and a whole import can be
  undone at once.

1.13.0
- Works with Trailhead (when it is active). Content planner: Create draft
  becomes Start in Trailhead, which opens Trailhead's New article form filled
  in from the plan. Queue for refresh also puts the article on Trailhead's
  refresh list, and refreshes finished in Trailhead show up in Refresh
  impact, measured from the latest refresh.

1.12.3
- Removed the Dashboard totals check from Settings.

1.12.2
- Dashboard: with All languages, the totals and daily charts are Search
  Console's own property total, with no page filter, so they match Search
  Console. A single language still filters by its folder.

1.12.1
- Settings > Check the Dashboard totals: compares Search Console's own total
  with the Dashboard's for the same dates and lists the page addresses the
  blog URL filter leaves out. Nothing is changed.

1.12.0
- New look in the Hostelworld design style. A side menu groups every report
  under Overview, Grow, Fix, Clean up and Data, with a count beside each report
  that has work waiting.
- New To-do page, now the first screen: each report with work waiting, most
  valuable first, plus the blog's last-28-day clicks and recently done actions.
- Restyled tables, buttons, filters, fields and overlap groups. On narrow
  screens the menu becomes compact buttons above the report.

1.11.0
- Ahrefs, alongside Search Console. Paste an Ahrefs API key under Settings >
  Ahrefs connection (or define HWCH_AHREFS_KEY in wp-config.php). The weekly
  sync then also pulls, in two bulk requests:
  - referring domains and backlinks for every blog page with links;
  - Ahrefs' estimated traffic, keyword count and top keyword per page.
- Candidates to prune: the Backlinks column shows each article's referring
  domains, linked to its backlinks in Ahrefs.
- Overlapping articles: a Backlinks column, and a "Most backlinks" badge when
  an article other than the suggested one has more sites linking to it.
- New Ahrefs tab: every article with Ahrefs data next to its Search Console
  clicks, plus two views: "Links but no search traffic" (keep their links:
  refresh or redirect, don't just unpublish) and "Ahrefs and Search Console
  disagree".
- Settings show the API units a sync costs for the row limits chosen, and
  Test connection shows the plan, units used this month and key expiry.

1.10.0
- Export any tab as CSV. The button under the tab bar downloads every row of
  that tab, not just the page on screen, with the language, view, filters and
  sort order you have on. Several tables on one tab come out as labelled
  blocks in the same file. The Actions column and buttons are left out, each
  article's address gets its own URL column, and numbers come out as plain
  numbers so they sort and sum. Opens cleanly in Excel and Google Sheets.

1.9.5
- Faster screens. Language checks, dismissed articles and page addresses are
  now read in a few database queries instead of one per article, which mainly
  speeds up Candidates to prune, Quick wins, Internal links, Tags & hubs and
  the dashboard.
- The sync saves every article's address, so the later steps and the reports
  reuse it instead of working out 40,000+ permalinks each time.
- The Content health box on the article screen now loads after the article
  opens, so it no longer holds up the editor.
- The internal links count and the box's article list are rebuilt in the
  background every hour, so nobody waits for them.

1.9.4
- Removed the Blog vs main site report, its sync steps and settings, and the
  data it stored. The refresh brief no longer has a main-site section.

1.9.3
- New setting, Main-site property: used only by Blog vs main site, so the
  plugin can see main-site pages while every other report keeps using the
  blog's own property. If the plugin's Google account has no access to it,
  the message says which account to add in Search Console.

1.9.2
- Blog vs main site: booking searches are now fetched from Google directly
  for both blog and main-site pages, instead of being matched against a
  sample of the blog's searches, so none are missed.
- Shared searches are shown at any position by default ("Both in the top 20"
  is a filter), so articles competing with the main site and losing to it
  are included.
- Booking words in every blog language and script (albergue, auberge de
  jeunesse, Jugendherberge, ostello, хостел, ホステル, 青年旅舍…).
- The report says what it checked, and explains when Google returns no
  main-site pages (a Search Console property covering only the blog).

1.9.1
- Sync now only fetches the page figures, which is quick. The rest
  (overlapping articles, planner searches, last year's figures, main-site
  searches, monthly figures) runs afterwards in the background, one part per
  request, so the sync no longer hits the server's time limit (a gateway
  error). A progress line at the top shows what is left; keeping the Content
  health screen open speeds it up, and the hourly job finishes anything left.
  A part cut off three times is skipped and named in the message at the top.
- Blog vs main site asks Google for booking searches only (a much smaller
  request), then separately for the main site's other searches.

1.9.0
- New: AI Overviews, Blog vs main site, and the Refresh brief.
- The content plan holds refreshes as well as new articles.
- The sync also stores each article's figures for the same period last
  year, and the main-site pages appearing for the blog's searches.

1.8.0
- New: Content planner (gaps by destination and topic, destinations
  overview, searches with no destination, and a content plan that creates
  drafts and shows the brief in the editor).
- The sync also stores the searches the English blog appears for.

1.7.2
- Destination levels (continent, country, city) are now also worked out from
  how articles are tagged: when at least 80% of the articles tagged Berlin are
  also tagged Germany, and Germany is on more articles, Germany is above
  Berlin. This works however the hub pages are built. Hub pages that are
  still drafts count for levels too (but are never suggested as links).
  Fixes articles with country and city tags being reported as continent-only.
- When an article does get "continent only", the message names the tags
  recognised and the tags that have no hub page yet.
- Language: without WPML or Polylang, an article's language is read from its
  address (/blog/de/…), so reports and the editor check that are English-only
  no longer include translations. The hub suggested in the editor is the
  English one.

1.7.1
- Internal links, the editor check and Overlapping articles now match
  destinations through the hub hierarchy (continent > country > city, from
  the hub pages' parent pages): suggestions come from the same city, or the
  same country when there is no city article, never from a shared continent.
  Within a destination, articles on a related topic come first. Articles
  tagged only with a continent get no suggestions, and say why.
- The editor check suggests the hub of the article's own city or country.
- The sync now stores each article's searches before the monthly figures,
  and spends at most 20 seconds on monthly figures; the hourly job fetches
  the rest. A sync cut short by the server's time limit can no longer leave
  Question coverage empty.
- Question coverage shows what it checked (searches stored, questions found,
  how many matched) and says when nothing is stored yet, instead of reporting
  that everything matched.
- The editor check also has its own sidebar, opened from the chart icon in
  the editor's top bar, and a "Content health" box in the Classic Editor.

1.7.0
- New: Seasonal planner, Question coverage, Broken links (internal, outbound,
  404s, redirect chains), Editor check, and chart annotations on the Dashboard.
- The sync now keeps each article's searches and monthly clicks.
- A partly failed sync names each part that failed; the rest is still saved.
