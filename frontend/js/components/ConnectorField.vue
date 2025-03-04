<template>
  <div>
    <template v-if="!keepAlive">
      <div v-if="open">
        <slot></slot>
      </div>
    </template>
    <template v-else>
      <div v-show="open">
        <slot></slot>
      </div>
    </template>
  </div>
</template>

<script>
  import isEqual from 'lodash/isEqual'
  import clone from 'lodash/clone'
  import { mapState, mapGetters } from 'vuex'

  export default {
    name: 'A17ConnectorField',
    props: {
      fieldName: {
        type: String,
        required: true
      },
      requiredFieldValues: {
        default: ''
      },
      inModal: {
        type: Boolean,
        default: false
      },
      keepAlive: {
        type: Boolean,
        default: false
      },
      isValueEqual: { // requiredFieldValues must be equal (or different) to the stored value to show
        type: Boolean,
        default: true
      },
      isBrowser: {
        type: Boolean,
        default: false
      },
      matchEmptyBrowser: {
        type: Boolean,
        default: false
      }
    },
    computed: {
      storedValue: function () {
        if (this.inModal) return this.modalFieldValueByName(this.fieldName)
        if (this.isBrowser) return this.selectedBrowser[this.fieldName]
        return this.fieldValueByName(this.fieldName)
      },
      ...mapGetters([
        'fieldValueByName',
        'modalFieldValueByName'
      ]),
      ...mapState({
        fields: state => state.form.fields, // Fields in the form
        modalFields: state => state.form.modalFields, // Fields in the create/edit modal
        selectedBrowser: state => state.browser.selected
      })
    },
    data: function () {
      return {
        open: false
      }
    },
    watch: {
      storedValue: function (fieldInstore) {
        this.toggleVisibility(fieldInstore)
      }
    },
    methods: {
      toggleVisibility: function (value) {
        if (this.isBrowser) {
          const browserLength = (value && value.length) ?? 0
          if (this.matchEmptyBrowser && (browserLength === 0)) {
            this.open = true
            return
          }

          this.open = this.matchEmptyBrowser ? false : browserLength > 0
          return
        }

        const newValue = clone(value)
        const newFieldValues = clone(this.requiredFieldValues)

        // sort requiredFieldValues and value if is array, so the order of values is the same
        if (Array.isArray(newFieldValues)) newFieldValues.sort()
        if (Array.isArray(newValue)) newValue.sort()

        // update visiblity
        if (this.isValueEqual) {
          this.open = (Array.isArray(newFieldValues)) ? newFieldValues.indexOf(newValue) !== -1 : isEqual(newValue, newFieldValues)
        } else {
          this.open = (Array.isArray(newFieldValues)) ? newFieldValues.indexOf(newValue) === -1 : !isEqual(newValue, newFieldValues)
        }
      }
    },
    mounted: function () {
      const self = this
      // init show/hide
      this.$nextTick(function () {
        self.toggleVisibility(this.storedValue)
      })
    }
  }
</script>
