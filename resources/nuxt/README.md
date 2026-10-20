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

## 4. Direct access — no session sends the visitor to CAO

Once CAO is the single sign-on authority, the SPA should not be usable without a
session, and it should not show a local form either. Add a gate in the guard:
after hydration, if there is **no** session and the route is not an
auth-utility route, send the browser to the **CAO frontend** home.

- Direct hit to the SPA (root, deep link, refresh) with no session → CAO frontend.
- Arriving back from CAO (SSO callback) → session exists → app proceeds normally.
- Already signed out at CAO → the CAO frontend lets them sign in again.

```ts
// routes/settings.ts
import { hydrateAuthSession, redirectToCaoHome } from "~/composables/useSessionHydration";

/**
 * Routes that stay reachable while signed out. Everything else redirects to the
 * CAO frontend when there is no valid session.
 */
const UNAUTHENTICATED_ROUTES = new Set<string>([
  "login", // initiates SSO when hit deliberately
  "forgot-password",
  "reset-password",
  "verify", // email verification link
  "unverified",
  "401",
  "403",
  "404",
]);

export const guard = async (to, from) => {
  const $auth = useAuthStore();
  const guard = useGuard();

  await hydrateAuthSession();

  if (!$auth.isLoggedIn && !UNAUTHENTICATED_ROUTES.has(to.name as string)) {
    redirectToCaoHome();
    // Abort navigation: the browser is leaving the app, so do not also
    // reject into /login (that would fight the full-page redirect).
    return Promise.reject(false);
  }

  return new Promise((resolve, reject) => {
    // ...rest of your guard...
  });
};
```

The guard rejects with `false` to mean "abort". Teach your guard plugin to
translate that into `next(false)`, otherwise vue-router treats a redirect-shaped
value as a navigation and can loop:

```ts
// plugins/03.routeGuard.ts
$router.beforeEach(async (to, from, next) => {
  try {
    await guard(to, from);
    next();
  } catch (e: any) {
    if (e === false) {
      next(false); // external navigation in progress
      return;
    }
    next(e);
  }
});
```

`redirectToCaoHome()` lives next to the hydration composable and uses the same
`caoFrontendUrl` runtime config (see section 6):

```ts
export const redirectToCaoHome = (): string => {
  const config = useRuntimeConfig();
  const url = config.public.caoFrontendUrl || "http://127.0.0.1:3000";
  if (import.meta.client) {
    window.location.href = url;
  }
  return url;
};
```

> Bouncing to the CAO **frontend** (home) is deliberate: a direct visit is not a
> login attempt, so the visitor lands on CAO rather than being pushed straight
> into an OAuth handshake. The `/login` route still exists and starts SSO for any
> code path that explicitly routes there.

## 5. Login button

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

## 6. Login page — auto-redirect to CAO

When CAO is the **only** way to authenticate, the local login form is redundant.
Two options are supported; both keep the `/login` **route** (so the router
guard's `reject({ name: "login" })` still resolves).

### Option A — keep the form (dual mode)

Leave your login page as-is and add the SSO button (section 5 above). Useful
during a migration where some users still have local passwords.

### Option B — auto-redirect, no form (recommended once SSO is the only path)

Replace the page body with a minimal component that sends the browser to CAO on
mount. No form is rendered.

```vue
<script setup lang="ts">
// CAO is the single sign-on provider; there is no local form.
const config = useRuntimeConfig();

const redirectToCao = () => {
  window.location.href = `${config.public.baseUrl}/auth/sso/cao`;
};

onMounted(redirectToCao);
</script>

<template>
  <div class="flex flex-col items-center gap-4 text-center">
    <TIcon name="tabler:loader-2" class="size-8 animate-spin text-primary" />
    <div class="text-2xl font-bold">Redirecting</div>
    <span class="text-muted text-sm">Taking you to CAO to sign in&hellip;</span>
    <TButton label="Continue to CAO SSO" @click="redirectToCao" />
  </div>
</template>
```

> **Do not delete the login route.** The guard redirects unauthenticated users
> to the named `login` route; if the route is missing, navigation fails instead
> of starting the SSO flow. Keep the route and change the component.

## 7. Logout — return to the CAO frontend

Because CAO is the SSO authority, a local logout must send the browser to the
CAO frontend (its home page). Simply reloading the app would bounce the user
straight back into SSO and silently sign them in again.

The server route (`POST /auth/logout`) invalidates the session; the client then
navigates to the CAO frontend URL.

Add a public runtime config value (so it differs per environment):

```ts
// nuxt.config.ts
runtimeConfig: {
  public: {
    caoFrontendUrl:
      process.env.NUXT_PUBLIC_CAO_FRONTEND_URL || "http://127.0.0.1:3000",
  },
},
```

```dotenv
# .env  (dev: CAO SPA is on :3000; prod: the CAO host)
NUXT_PUBLIC_CAO_FRONTEND_URL="http://127.0.0.1:3000"
```

Then redirect there on logout:

```ts
const logout = async (): Promise<any> => {
  const { $api } = useNuxtApp();
  const config = useRuntimeConfig();

  return new Promise((resolve, reject) => {
    $api
      .post("/auth/logout")
      .then(resolve)
      .catch(reject)
      .finally(() => {
        reset(); // clear the auth store
        window.location.href =
          config.public.caoFrontendUrl || "http://127.0.0.1:3000";
      });
  });
};
```

## Already signed in?

When the SSO callback completes it redirects to `cao-sso.frontend_url`. From the
user's point of view they are already logged in; hydration picks up the session
and the guard lets navigation proceed.
