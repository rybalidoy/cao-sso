# Nuxt client integration (CAO SSO)

This folder holds the **frontend pieces** for the SSO handoff. Nothing here is
applied automatically: the package never edits your pages, stores, router, or
`nuxt.config.ts`. **You copy the files and wire them in yourself.** This keeps
the integration opt-in and easy to review, and makes it portable across systems.

## Why anything is needed at all

The backend package logs the user in on the **server** and redirects the browser
back to the SPA. That is a **full page load**, so the in-memory/Pinia auth store
starts empty even though the session cookie is present. Without help, the router
guard sees `isLoggedIn === false` and bounces the freshly logged-in user back to
`/login`. So two frontend steps are required:

1. **Hydrate the auth store from the server session** before any auth decision.
2. **Make the route guard await hydration** before deciding access.

Everything else (login page, logout target, direct-access gate) is optional and
described below, in increasing order of opinion.

## Files in this folder

| File | Purpose | Required? |
| --- | --- | --- |
| `composables/useSessionHydration.ts` | Hydrate the store from the session; `redirectToCaoHome()` helper. | Yes |
| `components/CaoSsoLogin.vue` | Drop-in login screen that auto-redirects to CAO. | Optional |

> Adjust the import names (`useAuthStore`, the store's `setUser`, the `$api`
> plugin) to match your app. The examples assume an auth store with
> `setUser(data)` and an `isLoggedIn` getter.

---

## 1. (Required) Hydration composable

Copy `composables/useSessionHydration.ts` into `app/composables/`. It calls
`GET /auth/permissions` once per page load and populates your auth store. The
single-flight `hydration` promise means multiple callers share one request.

## 2. (Required) Hydrate on init

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

## 3. (Required) Await hydration in the route guard

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

That is the minimum for a working SSO redirect. The rest is opt-in.

---

## 4. (Optional) Login page — use the drop-in component

`components/CaoSsoLogin.vue` is a full-screen "Redirecting to CAO…" screen. It
sends the browser to the package's SSO route on mount, so there is **no local
form**. To adopt it you only change **your** route definition — the component
does not touch anything else:

```ts
// app/routes/index.ts
{
  path: "/login",
  name: "login",
  component: () => import("@/components/CaoSsoLogin.vue"),
  meta: {
    title: "Authentication",
    requiresAuth: false,
  },
}
```

> **Keep the route name (`login`) and its `meta` unchanged.** Your guard
> redirects unauthenticated users to the named `login` route; only the component
> changes. Do not delete the route.

The component also guards against a redirect loop: when the SSO callback fails it
redirects to `/login?error=<code>` (`sso_state_mismatch`, `sso_user_mismatch`,
`account_disabled`, `sso_failed`). The component detects `?error=` and, instead
of auto-redirecting again, shows the message with a **Try again** button.

### Prefer to keep your existing form?

Leave `login.vue` untouched and just add an SSO button, so local passwords keep
working during a migration:

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

## 5. (Optional) Direct access — no session sends the visitor to CAO

By default a signed-out visitor sees your login page. If instead you want the
SPA to bounce straight to the **CAO frontend** home on a direct hit (root, deep
link, refresh), add a gate at the top of the guard, after hydration:

```ts
import {
  hydrateAuthSession,
  redirectToCaoHome,
} from "~/composables/useSessionHydration";

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

`redirectToCaoHome()` ships in the hydration composable and uses the
`caoFrontendUrl` runtime config (see section 6).

> Bouncing to the CAO **frontend** (home) is deliberate: a direct visit is not a
> login attempt, so the visitor lands on CAO rather than being pushed straight
> into an OAuth handshake. The `/login` route still starts SSO when routed to.

## 6. (Optional) Logout — return to the CAO frontend

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

> **Gotcha: remove any "kick to login" watcher.** If your auth store has a
> watcher that reacts to `isLoggedIn` becoming `false` and pushes to the `login`
> route (a common pattern for handling session expiry), it will race the logout
> redirect: `reset()` flips `isLoggedIn`, the watcher navigates to `/login`, and
> because `/login` auto-redirects to SSO the user is signed straight back in.
>
> Example of the offending code to delete (often in an init plugin):
>
> ```ts
> watch(
>   () => auth.isLoggedIn,
>   (val) => {
>     $router.push(val ? { name: "home" } : { name: "login" });
>   },
> );
> ```
>
> The router guard already redirects unauthenticated users, and the SSO callback
> lands on `frontend_url` (root), so this watcher is redundant once SSO is in
> place. Removing it is what makes logout actually stick.

## Already signed in?

When the SSO callback completes it redirects to `cao-sso.frontend_url`. From the
user's point of view they are already logged in; hydration picks up the session
and the guard lets navigation proceed.
