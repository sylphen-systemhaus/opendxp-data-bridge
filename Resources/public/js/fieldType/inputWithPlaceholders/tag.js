/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.tags.dataBridgeInputWithPlaceholders");
opendxp.object.tags.dataBridgeInputWithPlaceholders = Class.create(opendxp.object.tags.input, {
  type: "dataBridgeInputWithPlaceholders",
  resolvedData: null,
  dataFields: {},
  updateRequest: null,

  initialize: function (data, fieldConfig) {
    this.resolvedData = '';

    var inputValue = '';
    if (data) {
      inputValue = data;
    }

    opendxp.object.tags.dataBridgeInputWithPlaceholders.superclass.prototype.initialize.call(this, inputValue, fieldConfig);
  },

  getLayoutEdit: function () {
    opendxp.object.tags.dataBridgeInputWithPlaceholders.superclass.prototype.getLayoutEdit.call(this);

    var fieldConfig = {
      fieldLabel: '&nbsp;',
      labelSeparator: '',
      labelWidth: 0,
      flex: 1,
      value: this.resolvedData,
      renderer: function (value) {
        return '<xmp style="white-space:pre-wrap;white-space:-moz-pre-wrap;white-space:-pre-wrap;white-space:-o-pre-wrap;">' + value + '</xmp>';
      }
    };

    var resolvedTextInput = Ext.create("Ext.form.field.Display", fieldConfig);

    this.component.addListener("blur", function () {
      this.updateResolvedText(this.component.getValue(), resolvedTextInput);
    }.bind(this));

    this.component.on('afterrender', function() {
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
                      this.updateResolvedText(this.component.getValue(), resolvedTextInput)
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
      layout: {
        type: 'hbox'
      },
      items: [
        this.component,
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
      if (Object.prototype.hasOwnProperty.call(this.dataFields, fieldName)) {
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
  }
});