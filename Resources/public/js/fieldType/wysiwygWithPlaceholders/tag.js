/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.tags.wysiwydataBridgeWysiwygWithPlaceholdersg");
/**
 * @private
 */
opendxp.object.tags.dataBridgeWysiwygWithPlaceholders = Class.create(opendxp.object.tags.abstract, {

  type: "dataBridgeWysiwygWithPlaceholders",
  updateRequest: null,

  initialize: function (data, fieldConfig) {
    this.data = "";
    if (data) {
      this.data = data;
    }
    this.fieldConfig = fieldConfig;
    this.editableDivId = "object_dataBridgeWysiwygWithPlaceholders_" + uniqid();
    this.dirty = false;
    this.resolvedTextInput = "";

    opendxp.object.tags.dataBridgeWysiwygWithPlaceholders.superclass.prototype.initialize.call(this, this.data, fieldConfig);
  },

  getGridColumnConfig: function (field) {
    var renderer = function (key, value, metaData, record) {
      this.applyPermissionStyle(key, value, metaData, record);

      try {
        if (record.data.inheritedFields && record.data.inheritedFields[key] && record.data.inheritedFields[key].inherited == true) {
          metaData.tdCls += " grid_value_inherited";
        }
      } catch (e) {
        console.log(e);
      }
      return value;

    }.bind(this, field.key);

    return {
      text: t(field.label), sortable: true, dataIndex: field.key, renderer: renderer,
      getEditor: this.getWindowCellEditor.bind(this, field)
    };
  },


  getGridColumnFilter: function (field) {
    return {type: 'string', dataIndex: field.key};
  },

  getLayout: function () {

    var iconCls = null;
    if(this.fieldConfig.noteditable == false) {
      iconCls = "opendxp_icon_droptarget";
    }

    var html = '<div class="opendxp_editable_dataBridgeWysiwygWithPlaceholders" id="' + this.editableDivId + '" contenteditable="true">' + this.data + '</div>';
    var pConf = {
      iconCls: iconCls,
      title: this.fieldConfig.title,
      html: html,
      border: true,
      bodyStyle: 'background: #fff',
      style: "margin-bottom: 10px",
      manageHeight: false,
      cls: "object_field object_field_type_" + this.type
    };

    if(this.fieldConfig.width) {
      pConf["width"] = this.fieldConfig.width;
    }

    if(this.fieldConfig.height) {
      pConf["height"] = this.fieldConfig.height;
      pConf["autoScroll"] = true;
    } else {
      pConf["autoHeight"] = true;
      pConf["autoScroll"] = true;
    }

    this.component = new Ext.Panel(pConf);
  },



  getLayoutShow: function () {
    this.getLayout();
    this.component.on("afterrender", function() {
      Ext.get(this.editableDivId).dom.setAttribute("contenteditable", "false");
    }.bind(this));
    this.component.disable();
    return this.component;
  },

  getLayoutEdit: function () {
    if (!this.fieldConfig.width) {
      this.fieldConfig.width = 660;
    }
    if (!this.fieldConfig.height) {
      this.fieldConfig.height = 200;
    }

    opendxp.object.tags.dataBridgeTextareaWithPlaceholders.superclass.prototype.getLayoutEdit.call(this);

    this.getLayout();
    this.component.on("afterlayout", this.startWysiwygEditor.bind(this));

    var width = this.fieldConfig.width;
    if (/^\d+$/.test(width)) {
      width += 'px';
    }

    var fieldConfig = {
      // fieldLabel: '&nbsp',
      value: this.resolvedData,
      width: 'calc('+ width+' / 2 + '+(this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100)+'px)',
      // height: this.fieldConfig.height,
      labelWidth: this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100,
      renderer: function (value) {
        return '<div style="padding-left:1rem;white-space:pre-wrap;white-space:-moz-pre-wrap;white-space:-pre-wrap;white-space:-o-pre-wrap;">' + value + '</xmp>';
      },
    };

    this.resolvedTextInput = Ext.create("Ext.form.field.Display", fieldConfig);

    this.getLayout();
    this.component.on("afterlayout", this.startWysiwygEditor.bind(this));

    if(this.ddWysiwyg) {
      this.component.on("beforedestroy", function () {
        const beforeDestroyWysiwyg = new CustomEvent(opendxp.events.beforeDestroyWysiwyg, {
          detail: {
            context: "object",
          },
        });

        document.dispatchEvent(beforeDestroyWysiwyg);
      }.bind(this));
    }

    this.component.on('afterrender', function () {
      setTimeout(function () {
        if (typeof Ext.getCmp("opendxp_panel_tabs").getActiveTab() !== "undefined" && typeof Ext.getCmp("opendxp_panel_tabs").getActiveTab().object !== "undefined") {
          this.dataFields = Ext.getCmp("opendxp_panel_tabs").getActiveTab().object.edit.dataFields;

          for (var fieldName in this.dataFields) {
            this.dataFields[fieldName].component.addListener("change", function () {
              this.updateResolvedText(this.getValue(), this.resolvedTextInput);
            }.bind(this, this.resolvedTextInput));
            if (this.dataFields[fieldName].type === 'wysiwyg' || this.dataFields[fieldName].type === 'dataBridgeWysiwygWithPlaceholders') {
              document.addEventListener(opendxp.events.changeWysiwyg, function (e) {
                  this.updateResolvedText(this.getValue(), this.resolvedTextInput);
              }.bind(this));
            }
          }
        }
        this.updateResolvedText(this.getValue(), this.resolvedTextInput);
      }.bind(this), 200);
    }.bind(this));

    return Ext.create("Ext.Panel", {
      cls: "object_field object_field_type_" + this.type,
      layout: {
        type: 'hbox'
      },
      items: [
        this.component,
        this.resolvedTextInput
      ]
    });
  },

  startWysiwygEditor: function () {

    if(this.ddWysiwyg) {
      return;
    }

    const initializeWysiwyg = new CustomEvent(opendxp.events.initializeWysiwyg, {
      detail: {
        config: this.fieldConfig,
        context: "object"
      },
      cancelable: true
    });
    const initIsAllowed = document.dispatchEvent(initializeWysiwyg);
    if(!initIsAllowed) {
      return;
    }

    const createWysiwyg = new CustomEvent(opendxp.events.createWysiwyg, {
      detail: {
        textarea: this.editableDivId,
        context: "object",
      },
      cancelable: true
    });
    const createIsAllowed = document.dispatchEvent(createWysiwyg);
    if(!createIsAllowed) {
      return;
    }

    document.addEventListener(opendxp.events.changeWysiwyg, function (e) {
      if (this.editableDivId === e.detail.e.target.id) {
        this.setValue(e.detail.data);
        this.updateResolvedText(this.getValue(), this.resolvedTextInput);
      }
    }.bind(this));

    if (!parent.opendxp.wysiwyg.editors.length) {
      Ext.get(this.editableDivId).dom.addEventListener("keyup", (e) => {
        this.setValue(Ext.get(this.editableDivId).dom.innerText);
      });
    }

    // add drop zone, use the parent panel here (container), otherwise this can cause problems when specifying a fixed height on the wysiwyg
    this.ddWysiwyg = new Ext.dd.DropZone(Ext.get(this.editableDivId).parent(), {
      ddGroup: "element",

      getTargetFromEvent: function(e) {
        return this.getEl();
      },

      onNodeOver : function(target, dd, e, data) {
        if (data.records.length === 1 && this.dndAllowed(data.records[0].data)) {
          return Ext.dd.DropZone.prototype.dropAllowed;
        }
      }.bind(this),

      onNodeDrop : this.onNodeDrop.bind(this)
    });
  },

  onNodeDrop: function (target, dd, e, data) {
    if (!opendxp.helpers.dragAndDropValidateSingleItem(data) || !this.dndAllowed(data.records[0].data) || this.inherited) {
      return false;
    }

    const onDropWysiwyg = new CustomEvent(opendxp.events.onDropWysiwyg, {
      detail: {
        target: target,
        dd: dd,
        e: e,
        data: data,
        context: "object",
        textareaId: this.editableDivId
      },
    });

    document.dispatchEvent(onDropWysiwyg);
  },

  dndAllowed: function(data) {

    if (data.elementType == "document" && (data.type=="page"
      || data.type=="hardlink" || data.type=="link")){
      return true;
    } else if (data.elementType=="asset" && data.type != "folder"){
      return true;
    } else if (data.elementType=="object" && data.type != "folder"){
      return true;
    }

    return false;
  },

  getValue: function () {
    return this.data;
  },

  setValue: function (value) {
    this.dirty = true;
    this.data = value;
  },

  getName: function () {
    return this.fieldConfig.name;
  },

  isDirty: function() {
    if(!this.isRendered()) {
      return false;
    }

    return this.dirty;
  },

  getWindowCellEditor: function (field, record) {
    return new opendxp.element.helpers.gridCellEditor({
        fieldInfo: field,
        elementType: "object"
      }
    );
  },

  getCellEditValue: function () {
    return this.getValue();
  },

  updateResolvedText: function (value, resolvedTextInput) {
    if(this.updateRequest !== null) {
      this.updateRequest.abort();
    }

    var fieldValues = {};
    for (var fieldName in this.dataFields) {
      if (Object.prototype.hasOwnProperty.call(this.dataFields, fieldName) && fieldName !== this.fieldConfig.name) {
        fieldValues[fieldName] = this.dataFields[fieldName].getValue();
      }
    }

    this.updateRequest = Ext.Ajax.request({
      url: '/admin/SylphenDataBridge/field/resolve',
      method: 'post',
      params: {
        input: value,
        objectId: this.object.id,
        fieldValues: JSON.stringify(fieldValues)
      },
      success: function (response) {
        try {
          response = Ext.decode(response.responseText);
          if (response && response.success) {
            resolvedTextInput.setValue(response.resolved);
          }
        } catch (e) {
          console.error(e);
        }
      }
    });
  },
});
