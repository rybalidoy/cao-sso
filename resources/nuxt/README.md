# Nuxt client integration (CAO SSO)

This folder holds the frontend pieces needed for the SSO handoff to work
reliably in a Nuxt SPA. Copy them into your app and wire them in as described.

The backend package logs the user in on the **server** and redirects the
browser back to the SPA. That is a **full page load**, so the in-memory/Pinia
auth store starts empty. Two things are required:

1. **Hydrate the auth store from the server session** before any auth decision.
2. **Make the route guard await hydration** so it does not bounce the user to
   `/login` before the session is checked.

## 1. Hydration composable

Copy `composables/useSessionHydration.ts` into `app/composables/`. It calls
`GET /auth/permissions` once per page load and populates your auth store.

> Adjust the import names (`useAuthStore`, the store's `setUser`, the `$api`
> plugin) to match your app. The example assumes an auth store with
> `setUser(data)` and an `isLoggedIn` getter.

## 2. Hydrate on init

In your init plugin (e.g. `app/plugins/04.init.client.ts`), hydrate before
loading other data:

```ts
const auth = useAuthStore();
const system = useSystemStore();
const status = useStatusStore();

try {
  await hydrateAuthSession();
  if (auth.isLoggedIn) {
    await Promise.all([system.fetchSettings(), status.fetchStatuses()]);
  }
} catch (error: any) {
  // 401 simply means "not logged in yet".
}
```

## 3. Await hydration in the route guard

In your route guard (e.g. `app/routes/settings.ts`), await hydration **before**
deciding access:

```ts
export const guard = async (to, from) => {
  const $auth = useAuthStore();
  const guard = useGuard();

  // Required after an SSO redirect (full page load).
  await hydrateAuthSession();

  return new Promise((resolve, reject) => {
    if (to.meta.requiresAuth === true && !$auth.isLoggedIn) {
      reject({ name: "login", query: { redirect: to.fullPath } });
    }
    // ...rest of your guard...
  });
};
```

## 4. Login button

To let users start the flow from the SPA as well, add a button that sends the
browser to the package's login route:

```vue
<script setup lang="ts">
const loginWithCao = () => {
  const config = useRuntimeConfig();
  window.location.href = `${config.public.baseUrl}/auth/sso/cao`;
};
</script>

<template>
  <TButton label="Login with CAO SSO" @click="loginWithCao" />
</template>
```

## Already signed in?

When the SSO callback completes it redirects to `cao-sso.frontend_url`. From the
user's point of view they are already logged in; hydration picks up the session
and the guard lets navigation proceed.
