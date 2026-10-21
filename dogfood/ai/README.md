# AI SDK demo

This is a local demo for maintainers and contributors, ported from Laravel AI's workbench. It includes a browser chat, an interactive terminal, and AG-UI routes for checking the SDK during development.

Run these commands from `dogfood/ai` with the framework's PHP extensions installed:

```sh
composer install
cp workbench/.env.example workbench/.env
touch workbench/database/database.sqlite
vendor/bin/testbench migrate
```

Set `OPENAI_API_KEY` in `workbench/.env` before sending prompts. Live prompts use the configured provider and incur its normal charges. The sample location, weather, temperature and email tools run locally; the email tool demonstrates approval without sending mail.

## Browser

Build the frontend, then start the server:

```sh
npm --prefix workbench ci
npm --prefix workbench run build
composer serve
```

Open <http://127.0.0.1:8000/chat>. For frontend development, run `npm --prefix workbench run dev` in another terminal instead of building the assets.

The server listens locally by default. The demo uses a shared sample user and has no authentication; it is intended for local development. Conversations are stored in `workbench/database/database.sqlite` and survive server restarts.

## Terminal

```sh
vendor/bin/testbench agent Assistant
```

The terminal streams responses and shows tool calls, approvals, reasoning and citations. Press Ctrl+D to submit a prompt, use `/help` for controls, or `/exit` to close the session. It requires an interactive terminal.

## Tests

```sh
composer test
```

These tests use AI fakes and an in-memory database; they need no provider credentials. They exercise browser conversation continuation and AG-UI streaming and history. They also run from the repository's `composer test:dogfood` command and PHP CI jobs.
