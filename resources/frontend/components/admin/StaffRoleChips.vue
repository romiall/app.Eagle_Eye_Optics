<script setup lang="ts">
/**
 * Profils d'un compte du personnel en puces (liste du personnel, fiche d'un compte ; F09) : une
 * puce par profil, au libellé du référentiel (« Accueil », « Caisse »), dorée pour la gérante ;
 * « Aucun profil » quand le compte n'en a aucun. Présentation seule : les profils affichés sont
 * ceux que le serveur a renvoyés, la puce ne les déduit pas.
 */
import StatusChip from '@/components/layout/StatusChip.vue'
import { roleLabel, rolesLabel } from '@/domain/access/staff'

const props = defineProps<{ roles: readonly string[] }>()
</script>

<template>
  <ul v-if="props.roles.length > 0" role="list" class="flex flex-wrap gap-1.5">
    <StatusChip
      v-for="role in props.roles"
      :key="role"
      as="li"
      :tone="role === 'MANAGER' ? 'gold' : 'info'"
      >{{ roleLabel(role) }}</StatusChip
    >
  </ul>
  <span v-else class="text-muted">{{ rolesLabel(props.roles) }}</span>
</template>
