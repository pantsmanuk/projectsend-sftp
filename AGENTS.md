# Package development

This directory is the boundary of the separately distributable SFTP package.
Read PLAN.md and HANDOVER.md before working. Complete only the requested task or
phase. Keep host application changes separate and explain any required integration.

The parent application is a release installation. This package has its own Git
repository; the parent's .git directory is empty. Development dependencies and
frontend build tooling are absent. Do not assume that PHPUnit, Pest, Pint, or npm
scripts work here. Never edit vendor.

Run the focused checks documented in HANDOVER.md. Record commands and results in
HANDOVER.md. Do not use real backups, credentials, or remote servers in tests.

In Blade connection labels, avoid `@{{` between separate interpolations: Blade
escapes the following expression. Render username, `@`, host, and port as a
single escaped expression. HTTP tests cover the resulting connection text.

Collision wraps the exception-handler contract in console processes when host
development dependencies are installed. Register `dontFlash` configuration
against Laravel's concrete `Handler`, as Laravel's `withExceptions` does; do not
type a contract-resolution callback as that concrete class. Keep web validation
credential-exclusion checks alongside the decorated-console boot regression.
