# agentlens promo plan

Goal: validate demand in 3–4 weeks on a fixed budget (gif + 1 article + posts).
Success = hundreds of Packagist downloads / dozens of stars / 1+ outside
issue or PR. Silence = park the package (maintenance only, no v2 features).

## 0. Launch readiness

- [x] CI green (PHP 8.2/8.3 × Laravel 11/12, prefer-lowest included)
- [x] Packagist live, installable via `composer require agentlens/agentlens`
- [x] Release notes for the latest tag
- [x] GitHub topics (`laravel`, `logging`, `ai-agents`, …)
- [x] Demo gif in README (collapsed block, 0.6 MB)
- [x] Benchmark table with real numbers (99.5% / 77–83%)

## 1. Headline (Laravel News submit)

Recommended:

> agentlens: 50 crashes, 3 log lines — runtime logging built for AI agents

Why: concrete numbers + curiosity + keyword "AI agents". The repo name is
shown automatically — don't waste title characters on it.

Alternatives:

- `Stop feeding your AI agent 170KB stack traces (agentlens for Laravel)`
- `Cut your AI agent's log reading by 99.5% with agentlens`

Submit: Laravel News → Submit Link, Tue–Thu morning US time. One sentence
description: "Zero-config Laravel package: humans keep the normal log,
AI agents get compact deduped JSON — `composer require agentlens/agentlens`."

## 2. Channels, in order

1. **Laravel News link** (above). Main Laravel audience entry point.
2. **X thread** (same day, 5 posts): 1) pain (wall of text screenshot);
   2) gif; 3) numbers table; 4) install + force flag; 5) repo link.
   No mass-tagging strangers.
3. **Reddit `r/laravel`** (next day): title with numbers, body = problem →
   gif → install. One post, answer every comment within hours.
4. **Dev.to article** (week 1): "My AI agent burned 42k tokens on one crash
   — now it's 226". Outline: pain story → numbers → how it works (short) →
   install → AGENTS.md snippet. This one lives in Google forever.
5. **Agent communities** (week 2): Cursor forum, Claude Code subreddits —
   lead with the token-money angle, not Laravel internals.
6. **Awesome lists**: PR adding the package to awesome-laravel collections.
7. **Show HN** (optional, only if Reddit shows traction): angle on agents,
   not on Laravel.

## 3. Demand test

- Metrics: Packagist downloads/month, stars, outside issues/PRs.
- Checkpoint +1 week (LN/X/Reddit done): any heartbeat? Checkpoint +4 weeks:
  verdict.
- Traction → roadmap comes from user feedback, not from our ideas list.
- Silence → feature freeze. The package works, costs nothing to keep.

## 4. Don'ts

- Same text everywhere on the same day (looks like a spam campaign).
- Promise v2 features (dashboards, sidecars) — promises kill trust in v1.
- Measure stars in week 1 — measure downloads in month 1.
- Argue in comments. Answer questions, fix real bugs within days.
