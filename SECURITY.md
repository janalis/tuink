# Security

Security fixes target the latest release. PHP must satisfy the package's supported runtime
requirements.

Report suspected vulnerabilities through the repository's
[private vulnerability reporting](https://github.com/janalis/tuink/security/advisories/new).
Do not open a public issue with exploit details.

Use a minimal synthetic reproduction. Remove credentials, personal information, private URLs and
proprietary source code from reports and attachments.

tuink is a test tool: the process driver runs the command its configuration names, with the
environment it gives, and `TuiProbe` listens on a Unix socket in a private temporary directory
created with mode 0700. Run test suites only against applications and configurations you trust.
