<script setup lang="ts">
/**
 * CAO SSO login screen — drop this in as the component for your `login` route.
 *
 * CAO is the single sign-on provider, so there is no local form: on mount the
 * browser is sent to the package's SSO route, which builds the OAuth redirect.
 *
 * Opt-in by design. This package never edits your existing pages. To use it,
 * point your `login` route at this component:
 *
 *   {
 *     path: "/login",
 *     name: "login",
 *     component: () => import("@/components/CaoSsoLogin.vue"),
 *     meta: { title: "Authentication", requiresAuth: false },
 *   }
 *
 * Keep the route name/`meta` as they are — your router guard redirects
 * unauthenticated users to the named `login` route.
 *
 * When the SSO callback fails it redirects back to `/login?error=...`. In that
 * case we must NOT auto-redirect again, or the browser would loop. We show the
 * error and let the user retry instead.
 */
const config = useRuntimeConfig();
const route = useRoute();

const error = computed(() => (route.query.error as string) || "");

const errorMessage = computed(() => {
  switch (error.value) {
    case "sso_state_mismatch":
      return "Your sign-in session expired. Please try again.";
    case "sso_user_mismatch":
      return "No matching account was found for your CAO user.";
    case "account_disabled":
      return "Your account has been disabled. Contact an administrator.";
    case "sso_failed":
    default:
      return "Sign-in failed. Please try again.";
  }
});

const redirectToCao = () => {
  window.location.href = `${config.public.baseUrl}/auth/sso/cao`;
};

onMounted(() => {
  // Only auto-start SSO when there is no error to display.
  if (!error.value) redirectToCao();
});
</script>

<template>
  <div
    class="bg-default/5 ring-default relative mx-auto w-full max-w-sm rounded-lg px-5 pt-16 pb-8 ring backdrop-blur-xs"
  >
    <div class="flex flex-col items-center gap-4 text-center">
      <template v-if="error">
        <TIcon name="tabler:alert-triangle" class="text-warning size-8" />
        <div class="text-2xl font-bold">Sign-in failed</div>
        <span class="text-muted text-sm">{{ errorMessage }}</span>

        <TButton
          label="Try again"
          variant="outline"
          color="primary"
          icon="tabler:refresh"
          @click="redirectToCao"
        />
      </template>

      <template v-else>
        <TIcon
          name="tabler:loader-2"
          class="text-primary size-8 animate-spin"
        />
        <div class="text-2xl font-bold">Redirecting</div>
        <span class="text-muted text-sm">
          Taking you to CAO to sign in&hellip;
        </span>

        <TButton
          label="Continue to CAO SSO"
          variant="outline"
          color="primary"
          icon="tabler:login"
          @click="redirectToCao"
        />
      </template>
    </div>
  </div>
</template>
