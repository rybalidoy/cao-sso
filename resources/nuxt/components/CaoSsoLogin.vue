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
 */
const config = useRuntimeConfig();

const redirectToCao = () => {
  window.location.href = `${config.public.baseUrl}/auth/sso/cao`;
};

onMounted(redirectToCao);
</script>

<template>
  <div
    class="bg-default/5 ring-default relative mx-auto w-full max-w-sm rounded-lg px-5 pt-16 pb-8 ring backdrop-blur-xs"
  >
    <div class="flex flex-col items-center gap-4 text-center">
      <TIcon name="tabler:loader-2" class="size-8 animate-spin text-primary" />
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
    </div>
  </div>
</template>
