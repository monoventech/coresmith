# Security

Report a vulnerability privately through GitHub: open this repository's Security tab and choose "Report a vulnerability". Please don't open a public issue for it.

Say what you found, how to reproduce it and which version you ran. You'll hear back within a few working days. Fixes ship as patch releases, which a self-hosted panel installs from Settings, General, or overnight when automatic patch updates are on.

## Verifying a release

Every release tag and every `install.sh` attached to a release is signed with the Coresmith release key. The key is in `resources/release-keys/` and at `https://get.coresmith.io/release-key.asc`, and its fingerprint is:

    10AD 393D 01B6 2CA5 524F  AEAB AF8B BF43 12DD D022

A panel checks this signature before it installs an update and refuses a release it can't verify.
