# vremote — documentation path map (2026-11-27)

- Repository: `vplus-vn/vremote`
- Audited branch: `main`
- Audited commit: `7c81edbefcc90e69fcb4b7e8484678157aa275b0`
- Recursive tree: 260 entries; truncated: false
- Inventory: 16 documentation/change artifacts.
- Canonical index: [docs/README.md](../README.md)

## Repository-specific source-of-truth rules

VRemote owns remote operator-to-edge-box display/OS control; sab-com-vn/edge-agent remains VBridge (web-to-hardware). `implementation/STATUS.md` is canonical; P1-STATUS and P1-implementation-status are historical snapshots superseded by STATUS.md. REQ/DSG-001 is DRAFT, DSG-002 tracks platform standardization. Keep API machine contracts in ../api/vremote and deployment procedure in ../deploy/DEPLOY.md; root changes and `docs/issue-148-hotfix-trigger.txt` require issue/PR lifecycle mapping.

## File-level inventory

| Current path | First-pass category | Owner | Disposition |
|---|---|---|---|
| `PROJECT.md` | Root documentation | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `README.md` | Root documentation | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `changes/vremote-std-corelib-apiv1-envelope-20260923.md` | Change proposal/history | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/INSTALL.md` | Install/usage/integration/index | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/PLATFORM-INTEGRATION-FEEDBACK.md` | Install/usage/integration/index | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/README.md` | Install/usage/integration/index | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/USAGE.md` | Install/usage/integration/index | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/design/DSG-VREMOTE-001-remote-admin-architecture.md` | Architecture/design | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/design/DSG-VREMOTE-002-platform-standardization.md` | Architecture/design | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/implementation/P1-STATUS.md` | Implementation status snapshot/runbook | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/implementation/P1-implementation-status.md` | Implementation status snapshot/runbook | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/implementation/P1-next-code.md` | Implementation/issue evidence | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/implementation/RUNBOOK.md` | Implementation/issue evidence | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/implementation/STATUS.md` | Implementation status snapshot/runbook | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/issue-148-hotfix-trigger.txt` | Install/usage/integration/index | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |
| `docs/requirements/REQ-VREMOTE-001-remote-admin.md` | Requirements | vremote maintainers; cross-repo owner where linked | Preserve; verify lifecycle, owner and canonical issue/PR/commit |

## Pending acceptance

- [x] Complete recursive tree captured; all in-scope documentation paths listed.
- [ ] Per-file lifecycle and canonical issue/PR/commit mapping.
- [ ] Compare implementation/runbook and code state read-only.
- [ ] Validate local/external links and anchors.
- [ ] Confirm owner before any move or supersession.

No code, tests, scripts, workflows, deployment/runtime configuration, secrets or protected files changed. No files moved or deleted.
