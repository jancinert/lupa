# Security

Lupa is made for development environments. It reads an application's configuration, code and database, so its safeguards (the environment guard, read-only database sessions, masking, the limits of the HTTP mode) are what a report most often concerns.

## Reporting a vulnerability

Please do not open a public issue for a problem that leaks data or gets around a safeguard. Report it privately with **Report a vulnerability** on the [Security tab](https://github.com/jancinert/lupa/security) of the repository, with the steps to reproduce it and the versions of Lupa, PHP and the libraries involved.

Fixes go into the latest release.

## What is not a vulnerability

Masking recognizes secrets by the names of keys and columns and personal data by their shape. A secret under an ordinary name or in an encoding it does not read is a limit the README describes, not a bypass; `masking.keys` and `masking.columns` are there for it. A report of a value that is masked in one place and shown in another is welcome.
