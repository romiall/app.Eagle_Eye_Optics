<script setup lang="ts">
/**
 * Liste de termes du référentiel à cocher (motifs de consultation, antécédents, motifs d'orientation ;
 * fichier 02, DP §4, §5 et §10). Les libellés viennent du référentiel (domain/clinical/terms). « Autre »
 * ouvre une précision obligatoire. Pour un groupe d'antécédents, « Aucun connu » est un choix à part :
 * il EXCLUT les autres valeurs du groupe, par désactivation réciproque (jamais une valeur effacée en
 * silence) ; un groupe sans aucune case cochée reste « non renseigné », ce qui n'est pas « Aucun
 * connu ». Un code que cette version ne connaît pas mais que le serveur a enregistré reste coché et
 * visible : il n'est jamais retiré à l'insu de la personne.
 */
import { computed } from 'vue'

import CheckboxGroup, { type CheckboxOption } from '@/components/form/CheckboxGroup.vue'
import TextField from '@/components/form/TextField.vue'
import {
  NONE_CODE,
  NONE_LABEL,
  OTHER_CODE,
  isKnownTerm,
  selectableTerms,
  termLabel,
  type TermGroup,
} from '@/domain/clinical/terms'
import {
  otherPrecisionLabel,
  setOtherText,
  TEXT_MAX_LENGTH,
  toggleTerm,
  type TermRow,
} from '@/domain/clinical/steps'

const props = withDefaults(
  defineProps<{
    group: TermGroup
    legend: string
    /** Identifiant du groupe de cases ; la précision de « Autre » porte `<idBase>-OTHER-precision`. */
    idBase: string
    rows: readonly TermRow[]
    /** Présent : le groupe propose « Aucun connu » ; absent : il n'en propose pas. */
    knownNone?: boolean | undefined
    hint?: string | undefined
    error?: string | null
    otherError?: string | null
    disabled?: boolean
  }>(),
  { knownNone: undefined, hint: undefined, error: null, otherError: null, disabled: false },
)

const emit = defineEmits<{
  'update:rows': [rows: TermRow[]]
  'update:knownNone': [value: boolean]
}>()

const hasNone = computed(() => props.knownNone !== undefined)

const options = computed<CheckboxOption[]>(() => {
  const list: CheckboxOption[] = []
  if (hasNone.value) {
    list.push({
      value: NONE_CODE,
      label: NONE_LABEL,
      disabled: props.knownNone !== true && props.rows.length > 0,
    })
  }
  const blocked = props.knownNone === true
  for (const term of selectableTerms(props.group)) {
    list.push({ value: term.code, label: term.label, disabled: blocked })
  }
  for (const row of props.rows) {
    if (!isKnownTerm(props.group, row.code)) {
      list.push({ value: row.code, label: termLabel(props.group, row.code), disabled: blocked })
    }
  }
  return list
})

const selected = computed(() => [
  ...(props.knownNone === true ? [NONE_CODE] : []),
  ...props.rows.map((row) => row.code),
])

const exclusivityHint = computed(() => {
  if (!hasNone.value) return undefined
  if (props.knownNone === true) {
    return '« Aucun connu » est coché : les autres valeurs sont désactivées. Décochez-le pour en choisir une.'
  }
  if (props.rows.length > 0) {
    return '« Aucun connu » est désactivé tant qu’une valeur est cochée. Un groupe sans case cochée reste « non renseigné ».'
  }
  return 'Un groupe sans case cochée reste « non renseigné » : ce n’est pas « Aucun connu ».'
})

const shownHint = computed(
  () =>
    [props.hint, exclusivityHint.value].filter((text) => text !== undefined).join(' ') || undefined,
)

const otherRow = computed(() => props.rows.find((row) => row.code === OTHER_CODE))

function update(values: string[]): void {
  const wantsNone = values.includes(NONE_CODE)
  if (hasNone.value && wantsNone !== (props.knownNone === true)) {
    emit('update:knownNone', wantsNone)
    return
  }
  const wanted = new Set(values.filter((value) => value !== NONE_CODE))
  let next = [...props.rows]
  for (const code of wanted) {
    if (!next.some((row) => row.code === code)) next = toggleTerm(props.group, next, code, true)
  }
  for (const row of props.rows) {
    if (!wanted.has(row.code)) next = toggleTerm(props.group, next, row.code, false)
  }
  emit('update:rows', next)
}

function updateOther(text: string): void {
  emit('update:rows', setOtherText(props.rows, OTHER_CODE, text))
}
</script>

<template>
  <CheckboxGroup
    :legend="props.legend"
    :options="options"
    :model-value="selected"
    :hint="shownHint"
    :error="props.error"
    :disabled="props.disabled"
    :control-id="props.idBase"
    @update:model-value="update"
  >
    <template #option="{ option, checked }">
      <div v-if="option.value === OTHER_CODE && checked" class="pb-2 pl-10">
        <TextField
          :model-value="otherRow?.otherText ?? ''"
          :label="otherPrecisionLabel(props.group)"
          :control-id="`${props.idBase}-OTHER-precision`"
          :maxlength="TEXT_MAX_LENGTH"
          :error="props.otherError"
          :disabled="props.disabled"
          required
          @update:model-value="updateOther"
        />
      </div>
    </template>
  </CheckboxGroup>
</template>
