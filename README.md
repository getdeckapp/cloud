# Deck Cloud Agent

**The slim, database-free [Deck](https://github.com/getdeckapp/deck) agent that streams queue observability to [Deck Cloud](https://deckapp.cloud).**

`deck/cloud` turns any Laravel app or worker into a Deck Cloud agent: it records
job executions and ships them to Deck Cloud over HTTP, reports queue worker
snapshots, and pulls and applies remote commands — **without a local database or
dashboard**. Install it on worker boxes where you want observability and remote
control but not the self-hosted UI.

For the full self-hosted experience (local database + Livewire dashboard), install
[`deck/deck`](https://github.com/getdeckapp/deck) instead — it already includes
this agent.

## What you get

- **Live execution streaming** — the HTTP sink subscribes to the kernel's
  `JobExecutionRecorded` event and batches executions to the Deck Cloud ingest API.
- **Worker reporting** — `deck:report-workers` reports Horizon (or plain queue)
  worker snapshots.
- **Remote commands** — `deck:poll-commands` pulls and applies commands from Deck
  Cloud: cancel, force-cancel, cancel-pending, block / unblock a job class,
  cancel-all-running-for-class, and retry. Every command is applied through
  cache/queue primitives using context carried on the command payload, so it
  works with **no local database**.

## Requirements

PHP 8.3+ · Laravel 11–13 · Redis (queues + cancel/block flags) · a Deck Cloud API key. [Horizon](https://laravel.com/docs/horizon) 5.x recommended for richer worker snapshots and failed-job retry.

## Installation

```bash
composer require deck/cloud
```

`deck/cloud` pulls in `deck/core` automatically. Configure the agent with the
standard Deck environment variables:

```env
DECK_API_KEY=your-agent-token
DECK_PROJECT=billing-api
DECK_ENVIRONMENT=production
```

The agent is opt-in: with no `DECK_API_KEY` set it stays completely inert. The
service provider (`Deck\Cloud\DeckCloudServiceProvider`) is auto-discovered and
schedules worker reporting and command polling when Cloud is enabled.

## Configuration

All settings live under the shared `deck.cloud.*` config namespace (see
`config/deck.php`); the `DECK_CLOUD_*` env variables are identical whether you run
the slim agent or the full `deck/deck` app.

## Development

```bash
composer test
composer analyse
```

## License

MIT © [Tor Morten Jensen](https://github.com/tormjens). See [LICENSE.md](LICENSE.md).
