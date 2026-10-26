# Gomez Garage Studio website

This repository is the source of truth for the existing website, including its
service read API. Continue the current implementation; read README.md and
ARCHITECTURE.md before changing site behavior. API details live in docs/agent-api/.

Preserve pre-existing work and the existing design. Keep checkout, payments,
invoices and inventory changes separate from integration-only work unless explicitly
requested. Never assume the local schema or checkout matches production.

Storefront copy: Romanian text must use no diacritics. Preserve the existing copy
and visual direction unless a change is requested. Describe the original design
positively; do not use the ambiguous Romanian phrase "Fara copii".

This repository is public. Do not commit config.php, .env, credentials, invoices,
customer data, private infrastructure details or internal project/security reviews.
Use only synthetic fixtures. Do not invoke real mail, payment or courier services
for verification. Keep consumer application code in its separate repository.

Established release workflow: publish approved changes to GitHub main; the owner
pulls them from cPanel. Do not propose manual ZIP/file uploads as the normal release
process. Git publication does not execute or authorize a production database migration
or an agent-triggered cPanel pull. Keep service API activation disabled by default.
Keep non-runtime repository folders and Git internals blocked by the web server.

For API code changes, run PHP lint, tests/agent/run.php, HTTP smoke and OpenAPI
validation. Run the isolated database/Apache tests when connection or web-server
behavior changes. Tests must never target an existing database or load config.php.
