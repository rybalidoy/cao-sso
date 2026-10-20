import type { AxiosInstance } from "axios";

/**
 * Ensures the auth store is hydrated from the server session exactly once per
 * page load, before any auth decision is made.
 *
 * This matters for SSO: the OAuth callback authenticates the user server-side
 * and redirects the browser back to the SPA with a full page load. At that
 * point the in-memory auth store is empty (isLoggedIn === false), so the router
 * guard would otherwise bounce the user straight back to /login before the
 * session is ever checked.
 */
let hydration: Promise<boolean> | null = null;

export const hydrateAuthSession = (): Promise<boolean> => {
  if (hydration) return hydration;

  const $auth = useAuthStore();
  const { $api } = useNuxtApp() as unknown as { $api: AxiosInstance };

  hydration = $api
    .get("/auth/permissions")
    .then((res) => {
      $auth.setUser(res.data.data);
      return $auth.isLoggedIn;
    })
    .catch(() => {
      // 401 simply means there is no active session.
      return $auth.isLoggedIn;
    });

  return hydration;
};
