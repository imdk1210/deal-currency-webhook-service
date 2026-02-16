Rollback policy: forward-only migrations.

1. Each migration must be idempotent where possible.
2. Failed migration execution is wrapped in a transaction and rolled back automatically.
3. To revert a bad migration in production:
   - restore from a DB backup, or
   - create and apply a new corrective migration.
4. Manual schema edits are forbidden; all changes must go through migration files.
