# Workflow: How to Add a New Module + MAJ (Updates)

---

## 1. Adding a New Module

When you create a new module (e.g. "invoices"), follow these 3 steps:

### Step 1: Backend — Register the feature key

**File: `config/features.php`**

Add the new key to the `modules` array with its Arabic name and group:

```php
'modules' => [
    // ... existing modules ...
    'invoices' => ['name' => 'الفواتير', 'group' => 'المالية'],
],
```

Then add it to the appropriate plan defaults:

```php
'plan_defaults' => [
    'free' => [...],              // Add here if free plan should have it
    'starter' => [...],           // Add here if starter plan should have it
    'pro' => [..., 'invoices'],   // Add here if pro plan should have it
    'business' => [..., 'invoices'], // Always add to business (gets everything)
],
```

### Step 2: Backend — Protect the routes

**File: `routes/api.php`**

Wrap your new API routes with the `check.feature` middleware:

```php
Route::middleware('check.feature:invoices')->group(function () {
    Route::apiResource('invoices', InvoiceController::class);
    // ... other invoice routes
});
```

### Step 3: Frontend — Add to sidebar with feature key

**File: `frontend-demo/src/components/DashboardLayout.tsx`**

Add the new menu item with a `feature` property matching the key from Step 1:

```typescript
{
  name: 'الفواتير',
  href: '/dashboard/invoices',
  icon: DocumentIcon,
  feature: 'invoices',    // <-- Must match the key in config/features.php
},
```

### Done!

- The admin will see the new module in the "الميزات والوحدات" section on the tenant detail page
- Tenants on plans that include it will see it automatically
- The admin can manually toggle it on/off per tenant
- Backend blocks API access if the feature is disabled (403)
- Frontend hides the sidebar item if the feature is disabled

---

## 2. Pushing Updates (MAJ) to Tenants

### When you make backend changes (new migrations, code changes):

1. **Bump the version** in `.env`:
   ```
   TENANT_APP_VERSION=1.1
   ```

2. **From the admin panel**:
   - Go to a tenant's detail page
   - If the tenant is outdated, you'll see an orange banner with "تحديث إلى vX.X"
   - Click the button to push the update (runs migrations on that tenant's database)

3. **Bulk update all tenants**:
   - Go to the admin dashboard
   - If there are pending updates, you'll see a banner with "تحديث الكل"
   - This updates all tenants that have `updates_enabled = true`

4. **Via command line**:
   ```bash
   php artisan tenant:migrate              # Migrate all active tenants
   php artisan tenant:migrate --tenant=5   # Migrate specific tenant
   php artisan tenant:migrate --updates-only  # Only tenants with updates enabled
   ```

### When you make frontend changes:

Frontend changes are deployed globally (same Next.js build for all tenants). No per-tenant action needed — just deploy the new build.

### When you add a new module:

Follow the "Adding a New Module" steps above, then:
1. Bump `TENANT_APP_VERSION` if there are new migrations
2. Push updates to tenants from the admin panel
3. Toggle the new feature on for the tenants that should have it

---

## 3. Quick Reference: Key Files

| Purpose | File |
|---------|------|
| Feature definitions & plan defaults | `backend/config/features.php` |
| Feature check middleware | `backend/app/Http/Middleware/CheckFeature.php` |
| Tenant model (features logic) | `backend/app/Models/Tenant.php` |
| API routes (middleware wrapping) | `backend/routes/api.php` |
| Sidebar menu items | `frontend-demo/src/components/DashboardLayout.tsx` |
| Auth store (features fetch) | `frontend-demo/src/lib/store/auth.ts` |
| Admin API calls | `frontend-demo/src/lib/admin-api.ts` |
| Tenant version | `.env` → `TENANT_APP_VERSION` |
| Migration command | `backend/app/Console/Commands/TenantMigrate.php` |

---

## 4. Checklist for New Module

- [ ] Create migration(s) in `database/migrations/`
- [ ] Create Model, Controller, Request classes
- [ ] Add routes in `routes/api.php` wrapped with `check.feature:key`
- [ ] Add feature key to `config/features.php` (modules + plan_defaults)
- [ ] Add menu item in `DashboardLayout.tsx` with `feature: 'key'`
- [ ] Create frontend page(s) in `frontend-demo/src/app/dashboard/`
- [ ] Add API functions in `frontend-demo/src/lib/api.ts`
- [ ] Bump `TENANT_APP_VERSION` in `.env`
- [ ] Push updates to tenants from admin panel
- [ ] Toggle feature on for appropriate tenants
