<template>
  <div class="content">
    <draggable class="content__content" v-model="blocks" :options="dragOptions">
      <transition-group name="draggable_list" tag='div'>
        <div class="content__item" v-for="(block, index) in blocks" :key="block.id">
          <a17-blockeditor-item
            ref="blockList"
            :block="block"
            :index="index"
            :size="blockSize"
            :opened="opened"
          >
            <a17-button slot="block-actions" variant="icon" data-action @click="duplicateBlock(index)" v-if="hasRemainingBlocks">
              <span v-svg symbol="add"></span>
            </a17-button>
            <div slot="dropdown-action">
              <button type="button" @click="collapseAllBlocks()" v-if="opened">
                {{ $trans('fields.block-editor.collapse-all', 'Collapse all') }}
              </button>
              <button v-else type="button" @click="expandAllBlocks()">
                {{ $trans('fields.block-editor.expand-all', 'Expand all') }}
              </button>
              <button type="button" @click="duplicateBlock(index)" v-if="hasRemainingBlocks">
                {{ $trans('fields.block-editor.clone-block', 'Clone block') }}
              </button>
              <button type="button" @click="deleteBlock(index)">
                {{ $trans('fields.block-editor.delete', 'Delete') }}
              </button>
            </div>
          </a17-blockeditor-item>
        </div>
      </transition-group>
    </draggable>
    <div class="content__trigger">
      <a17-button
        v-if="hasRemainingBlocks && blockType.trigger"
        :class="triggerClass"
        :variant="triggerVariant"
        @click="addBlock()"
      >
        {{ blockType.trigger }}
      </a17-button>
      <div class="content__note f--note f--small">
        <slot></slot>
      </div>
    </div>
  </div>
</template>

<script>
  import { mapState } from 'vuex'
  import { FORM } from '@/store/mutations'

  import draggable from 'vuedraggable'
  import draggableMixin from '@/mixins/draggable'
  import BlockEditorItem from '@/components/blocks/BlockEditorItem.vue'

  export default {
    name: 'A17Repeater',
    components: {
      'a17-blockeditor-item': BlockEditorItem,
      draggable
    },
    mixins: [draggableMixin],
    props: {
      type: {
        type: String,
        required: true
      },
      name: {
        type: String,
        required: true
      },
      buttonAsLink: {
        type: Boolean,
        default: false
      },
      max: {
        type: [Number, null],
        required: false,
        default: null
      }
    },
    data: function () {
      return {
        opened: true,
        handle: '.block__handle' // drag handle
      }
    },
    computed: {
      triggerVariant: function () {
        if (this.buttonAsLink) {
          return 'aslink'
        }
        return this.inContentEditor ? 'outline' : 'action'
      },
      triggerClass: function () {
        return this.inContentEditor ? 'content__button' : ''
      },
      blockSize: function () {
        return this.inContentEditor ? 'small' : ''
      },
      inContentEditor: function () {
        return typeof this.$parent.repeaterName !== 'undefined'
      },
      hasRemainingBlocks: function () {
        let max = null
        if (this.max && this.max > 0) {
          max = this.max
        } else if (this.blockType.hasOwnProperty('max')) {
          max = this.blockType.max
        }
        return !max || (max > this.blocks.length)
      },
      blockType: function () {
        return this.availableBlocks[this.type] ? this.availableBlocks[this.type] : {}
      },
      blocks: {
        get () {
          if (this.savedBlocks.hasOwnProperty(this.name)) {
            return this.savedBlocks[this.name] || []
          } else {
            return []
          }
        },
        set (value) {
          this.$store.commit(FORM.REORDER_FORM_BLOCKS, {
            type: this.type,
            name: this.name,
            blocks: value
          })
        }
      },
      ...mapState({
        savedBlocks: state => state.repeaters.repeaters,
        availableBlocks: state => state.repeaters.availableRepeaters
      })
    },
    methods: {
      addBlock: function () {
        this.$store.commit(FORM.ADD_FORM_BLOCK, { type: this.type, name: this.name })
      },
      duplicateBlock: function (index) {
        this.opened = true
        this.$store.commit(FORM.DUPLICATE_FORM_BLOCK, {
          type: this.type,
          name: this.name,
          index: index
        })
      },
      deleteBlock: function (index) {
        this.$store.commit(FORM.DELETE_FORM_BLOCK, {
          type: this.type,
          name: this.name,
          index: index
        })
      },
      collapseAllBlocks: function () {
        this.opened = false
      },
      expandAllBlocks: function () {
        this.opened = true
      }
    },
    mounted: function () {
      const self = this
      this.$nextTick(function () {
        self.collapseAllBlocks()
      })
    }
  }
</script>

<style lang="scss" scoped>

  .content {
    margin-top: 20px; // margin-top:35px;
  }

  .content__content {
    margin-bottom: 20px;

    + .dropdown {
      display: inline-block;
    }
  }

  .content__item {
    border: 1px solid $color__border;
    border-top: 0 none;

    &.sortable-ghost {
      opacity: 0.5;
    }
  }

  .content__item:first-child {
    border-top: 1px solid $color__border;
  }

  .content__trigger {
    display: flex;
  }

  .content__button {
    margin-top: -5px;
  }

  .button--aslink {
    display: block;
    width: 100%;
    text-align: center;
  }

  .content__note {
    flex-grow: 1;
    text-align: right;
  }
</style>
