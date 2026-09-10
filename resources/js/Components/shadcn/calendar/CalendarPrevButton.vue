<script setup>
import { ChevronLeft } from "@lucide/vue";
import { reactiveOmit } from "@vueuse/core";
import { CalendarPrev, useForwardProps } from "reka-ui";
import { cn } from "@/lib/utils";
import { buttonVariants } from '@/Components/shadcn/button';

const props = defineProps({
  prevPage: { type: Function, required: false },
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
  <CalendarPrev
    data-slot="calendar-prev-button"
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
      <ChevronLeft class="size-4" />
    </slot>
  </CalendarPrev>
</template>
