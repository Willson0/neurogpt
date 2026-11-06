# NeuroGPT backend

Laravel 13 API for the NeuroGPT Telegram Mini App. Setup and deployment are described in the README at the repository root.

- `routes/api.php`: all endpoints
- `app/Services/Telegram`: initData validation, user resolver, bot client
- `app/Services/Payments`: YooKassa client and payment fulfilment
- `app/Services/Generation`: provider drivers (`openai`, `replicate`, `fake`)
- `config/neurogpt.php`: prices, packs, plans, bonuses, provider settings
