/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.tags.dataBridgeTextareaWithPlaceholders");
opendxp.object.tags.dataBridgeTextareaWithPlaceholders = Class.create(opendxp.object.tags.textarea, {
  type: "dataBridgeTextareaWithPlaceholders",
  resolvedData: null,
  dataFields: {},
  editorContainer: null,
  updateRequest: null,

  initialize: function (data, fieldConfig) {
    this.resolvedData = '';

    var inputValue = '';
    if (data) {
      inputValue = data;
    }

    opendxp.object.tags.dataBridgeTextareaWithPlaceholders.superclass.prototype.initialize.call(this, inputValue, fieldConfig);
  },

  getLayoutEdit: function () {
    if (!this.fieldConfig.width) {
      this.fieldConfig.width = 500;
    }
    if (!this.fieldConfig.height) {
      this.fieldConfig.height = 250;
    }

    opendxp.object.tags.dataBridgeTextareaWithPlaceholders.superclass.prototype.getLayoutEdit.call(this);
    this.component.hide();

    var editorId = 'object_' + this.object.id + '_' + this.fieldConfig.name + '_' + Ext.id();

    var width = this.fieldConfig.width;
    if (/^\d+$/.test(width)) {
      width += 'px';
    }

    var fieldConfig = {
      fieldLabel: this.fieldConfig.title,
      value: '<div id="' + editorId + '" style="height:' + this.fieldConfig.height + 'px;width:calc(' + this.fieldConfig.width + '/2)"></div>',
      width: 'calc('+ width+' / 2 + '+(this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100)+'px)',
      height: this.fieldConfig.height,
      labelWidth: this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100,
      readOnly: this.fieldConfig.noteditable,
      listeners: {
        afterrender: function (cmp) {
          var editor = ace.edit(editorId);
          editor.setTheme('ace/theme/chrome');
          editor.session.setMode('ace/mode/twig');

          var languageTools = ace.require("ace/ext/language_tools");
          editor.completers = [languageTools.snippetCompleter, languageTools.textCompleter];
          editor.setOptions({
            showLineNumbers: false,
            showGutter: false,
            indentedSoftWrap: false,
            showPrintMargin: false,
            wrap: true,
            readOnly: this.fieldConfig.noteditable,
            fontFamily: 'Courier New, Courier, monospace;',
            enableBasicAutocompletion: true,
            enableSnippets: true,
            enableLiveAutocompletion: true
          });

          editor.setValue(this.data || '');
          editor.clearSelection();
          editor.on("blur", function () {
            this.updateResolvedText(editor.getValue(), resolvedTextInput);
          }.bind(this));
          editor.on('change', function () {
            this.component.setValue(editor.getValue());
          }.bind(this));
        }.bind(this)
      }
    };
    if (this.fieldConfig.labelAlign) {
      fieldConfig.labelAlign = this.fieldConfig.labelAlign;
    }
    this.editorContainer = Ext.create("Ext.form.field.Display", fieldConfig);

    var fieldConfig = {
      fieldLabel: '',
      labelSeparator: '',
      labelWidth: 0,
      flex: 1,
      margin: '0 15',
      value: this.resolvedData,
      renderer: function(value) {
        return '<xmp style="white-space:pre-wrap;white-space:-moz-pre-wrap;white-space:-pre-wrap;white-space:-o-pre-wrap;">'+value+'</xmp>';
      }
    };

    var resolvedTextInput = Ext.create("Ext.form.field.Display", fieldConfig);

    this.component.on('afterrender', function () {
      setTimeout(function () {
        if (typeof Ext.getCmp("opendxp_panel_tabs").getActiveTab() !== "undefined" && typeof Ext.getCmp("opendxp_panel_tabs").getActiveTab().object !== "undefined") {
          this.dataFields = Ext.getCmp("opendxp_panel_tabs").getActiveTab().object.edit.dataFields;

          for (var fieldName in this.dataFields) {
            if (Object.prototype.hasOwnProperty.call(this.dataFields, fieldName)) {
              if (fieldName !== this.fieldConfig.name) {
                this.dataFields[fieldName].component.on('change', function () {
                  this.updateResolvedText(this.component.getValue(), resolvedTextInput);
                }.bind(this));

                if (typeof this.dataFields[fieldName].component.items !== "undefined" && this.dataFields[fieldName].component.items !== null) {
                  Ext.each(this.dataFields[fieldName].component.items.items, function (subField) {
                    subField.on('change', function () {
                      this.updateResolvedText(this.component.getValue(), resolvedTextInput);
                    }.bind(this));

                    if (typeof subField.store !== "undefined") {
                      subField.store.on('datachanged', function () {
                        this.updateResolvedText(this.component.getValue(), resolvedTextInput);
                      }.bind(this));
                    }
                  }.bind(this));
                }
              }
            }
          }
        }
      }.bind(this), 100);

      this.updateResolvedText(this.component.getValue(), resolvedTextInput);
    }.bind(this));

    return Ext.create("Ext.Panel", {
      cls: "object_field object_field_type_" + this.type,
      height: this.fieldConfig.height,
      width: this.sumWidths(this.fieldConfig.width, this.fieldConfig.labelWidth ? this.fieldConfig.labelWidth : 100),
      layout: {
        type: 'hbox'
      },
      items: [
        this.component,
        this.editorContainer,
        resolvedTextInput
      ]
    });
  },

  updateResolvedText: function (value, resolvedTextInput) {
    if (this.updateRequest !== null) {
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

  sumWidths: function (width1, width2) {
    if (/^\d+$/.test(width1) && /^\d+$/.test(width2)) {
      return parseInt(width1) + parseInt(width2);
    }
    if (/^\d+$/.test(width1)) {
      width1 += 'px';
    }
    if (/^\d+$/.test(width2)) {
      width2 += 'px';
    }

    return 'calc(' + width1 + ' + ' + width2 + ')';
  },

  markInherited: function (metaData) {
    var el = this.editorContainer.getEl();
    if (el) {
      el.addCls("object_value_inherited");
    }
    this.addInheritanceSourceButton(metaData);
  },

  unmarkInherited: function () {
    var el = this.editorContainer.getEl();
    if (el) {
      el.removeCls("object_value_inherited");
      this.removeInheritanceSourceButton();
    }
  },

  getWrappingEl: function () {
    var el = this.editorContainer.getEl();
    try {
      if (el && !el.hasCls("object_field")) {
        el = el.parent(".object_field");
      }
    } catch (e) {
      console.log(e);
      return;
    }

    return el;
  }
});