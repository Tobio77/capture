<script setup>
import { ChevronRight } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { CalendarNext, useForwardProps } from "reka-ui";
import { cn } from "@/lib/utils";
import { buttonVariants } from '@/Components/shadcn/button';

const props = defineProps({
  nextPage: { type: Function, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});

const delegatedProps = reactiveOmit(props, "class");

const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
  <CalendarNext
    data-slot="calendar-next-button"
    :class="
      cn(
        buttonVariants({ variant: 'ghost' }),
        'size-8 bg-transparent p-0 text-sekunder hover:bg-permukaan-hover hover:text-utama',
        props.class,
      )
    "
    v-bind="forwardedProps"
  >
    <slot>
      <ChevronRight class="size-4" />
    </slot>
  </CalendarNext>
</template>
