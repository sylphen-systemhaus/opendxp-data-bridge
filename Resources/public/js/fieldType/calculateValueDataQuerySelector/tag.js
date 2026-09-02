/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.tags.dataBridgeCalculatedValueDataQuerySelector");
opendxp.object.tags.dataBridgeCalculatedValueDataQuerySelector = Class.create(opendxp.object.tags.calculatedValue, {
  type: "dataBridgeCalculatedValueDataQuerySelector",
  dataFields: {},

  initialize: function (data, fieldConfig) {
    this.fieldConfig = fieldConfig;
    opendxp.object.tags.dataBridgeCalculatedValueDataQuerySelector.superclass.prototype.initialize.call(this, data, fieldConfig);
  },

  updateCalculatedValue: function () {
    var fieldValues = {};
    for (var fieldName in this.dataFields) {
      if (Object.prototype.hasOwnProperty.call(this.dataFields, fieldName) && fieldName !== this.fieldConfig.name) {
        fieldValues[fieldName] = this.dataFields[fieldName].getValue();
      }
    }

    var fieldName = this.fieldConfig.name;
    if(typeof this.context !== "undefined" && typeof this.context.language !== "undefined") {
      fieldName += "#" + this.context.language;
    }

    Ext.Ajax.request({
      url: '/admin/SylphenDataBridge/field/resolve',
      method: 'post',
      params: {
        field: fieldName,
        objectId: this.object.id,
        fieldValues: JSON.stringify(fieldValues)
      },
      success: function (response) {
        try {
          response = Ext.decode(response.responseText);
          if (response && response.success) {
            this.component.setValue(response.resolved);
          }
        } catch (e) {
          console.error(e);
        }
      }.bind(this)
    });
  },

  getLayoutEdit: function () {
    var component = opendxp.object.tags.dataBridgeCalculatedValueDataQuerySelector.superclass.prototype.getLayoutEdit.call(this);

    component.setFieldLabel('<img src="/bundles/opendxpadmin/img/flat-color-icons/calculator.svg" style="height: 17px; display: inline-block; vertical-align: middle;"/>' + this.fieldConfig.title);

    component.on('afterrender', function () {
      setTimeout(function () {
        if(typeof Ext.getCmp("opendxp_panel_tabs").getActiveTab() !== "undefined" && typeof Ext.getCmp("opendxp_panel_tabs").getActiveTab().object !== "undefined") {
          this.dataFields = Ext.getCmp("opendxp_panel_tabs").getActiveTab().object.edit.dataFields;

          for (var fieldName in this.dataFields) {
            if (Object.prototype.hasOwnProperty.call(this.dataFields, fieldName)) {
              if (fieldName !== this.fieldConfig.name) {
                this.dataFields[fieldName].component.on('change', function () {
                  this.updateCalculatedValue();
                }.bind(this));

                if (typeof this.dataFields[fieldName].component.items !== "undefined" && this.dataFields[fieldName].component.items !== null) {
                  Ext.each(this.dataFields[fieldName].component.items.items, function (subField) {
                    subField.on('change', function () {
                      this.updateCalculatedValue();
                    }.bind(this));

                    if(typeof subField.store !== "undefined") {
                      subField.store.on('datachanged', function () {
                        this.updateCalculatedValue();
                      }.bind(this));
                    }
                  }.bind(this));
                }
              }
            }
          }
        }
      }.bind(this), 100);
    }.bind(this));

    return component;
  }
});