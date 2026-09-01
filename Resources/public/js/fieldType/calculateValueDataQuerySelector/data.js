/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.classes.data.dataBridgeCalculatedValueDataQuerySelector");
opendxp.object.classes.data.dataBridgeCalculatedValueDataQuerySelector = Class.create(opendxp.object.classes.data.calculatedValue, {
  type: "dataBridgeCalculatedValueDataQuerySelector",

  initialize: function (treeNode, initData) {
    this.type = "dataBridgeCalculatedValueDataQuerySelector";

    this.initData(initData);

    this.treeNode = treeNode;
  },

  getTypeName: function () {
    return t("pim.field-type.calculated-value-data-query-selector");
  },

  getLayout: function ($super) {

    $super();

    const dataQuerySelector = Ext.create('Ext.form.TextArea', {
      fieldLabel: t('pim.dataport.opendxp.fields.data_query_selector'),
      labelWidth: 140,
      name: 'dataQuerySelector',
      value: this.datax.dataQuerySelector,
      width: '100%',
      grow: true
    });

    this.specificPanel.removeAll();
    this.specificPanel.add([
      {
        xtype: "combo",
        fieldLabel: t("type"),
        name: "elementType",
        value: this.datax.elementType,
        labelWidth: 140,
        store: [
          ['input', t('input')],
          ['textarea', t('textarea')],
          ['html', t('html')]
        ]
      },
      {
        xtype: "textfield",
        fieldLabel: t("width"),
        name: "width",
        value: this.datax.width,
        labelWidth: 140
      },
      {
        xtype: "displayfield",
        hideLabel: true,
        value: t('width_explanation')
      },
      {
        xtype: "numberfield",
        fieldLabel: t("columnlength"),
        name: "columnLength",
        value: this.datax.columnLength,
        labelWidth: 140
      },
      dataQuerySelector,
      {
        xtype: "displayfield",
        hideLabel: true,
        value: t('pim.dataport.opendxp.fields.data_query_selector.hint'),
        cls: "opendxp_extra_label_bottom",
        style: "color:red; font-weight: bold; padding-bottom:0;"
      }
    ]);

    return this.layout;
  },

  applySpecialData: function (source) {
    if (source.datax) {
      if (!this.datax) {
        this.datax = {};
      }
      Ext.apply(this.datax,
          {
            dataQuerySelector: source.datax.dataQuerySelector,
            elementType: source.datax.elementType,
            width: source.datax.width,
            columnLength: source.datax.columnLength
          }
      );
    }
  }
});