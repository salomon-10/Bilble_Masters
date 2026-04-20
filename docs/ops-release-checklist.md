# Ops and Release Checklist (V1)

## 1) Database backup (non-negotiable before production release)

Run one of these commands before migrations:

```bash
mysqldump -u root -p bible_master > backup_bible_master_$(date +%Y%m%d_%H%M%S).sql
```

If root has no password on local XAMPP:

```bash
mysqldump -u root bible_master > backup_bible_master_$(date +%Y%m%d_%H%M%S).sql
```

## 2) Apply migration (versioned)

```bash
mysql -u root -p bible_master < database/migrations/20260417_add_match_change_logs.sql
```

## 3) Manual E2E non-regression tests

1. Open `/admin/login.php`.
2. Login with a valid admin account.
3. Confirm redirect to `/admin/dashboard.php`.
4. Click "Creer" and create a match.
5. Confirm match appears in dashboard list.
6. Open `/admin/visibilite.php`.
7. Update score, status, published flag for that match.
8. Confirm success message appears.
9. Confirm a new entry appears in "Journal des modifications".
10. Open `/user/index.php`.
11. Confirm match visibility follows the published flag.
12. Confirm score and status match admin update.
13. Logout using `/admin/logout.php`.

## 4) Security checks before production

1. `APP_ENV=production` is set.
2. `BIBLE_BOOTSTRAP_ADMIN` is not set to `1`.
3. At least one admin account exists with a strong password.
4. Login brute-force throttle works after repeated failures.
5. All admin pages require authentication.

## 5) Broken link checks

Verify these routes open correctly:

- `/admin/login.php`
- `/admin/dashboard.php`
- `/admin/create_match.php`
- `/admin/visibilite.php`
- `/admin/set_score.php`
- `/user/index.php`
