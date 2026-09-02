/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.classes.data.dataBridgeInputWithPlaceholders");
opendxp.object.classes.data.dataBridgeInputWithPlaceholders = Class.create(opendxp.object.classes.data.input, {
  type: "dataBridgeInputWithPlaceholders",

  initialize: function (treeNode, initData) {
    this.type = "dataBridgeInputWithPlaceholders";

    this.initData(initData);

    this.treeNode = treeNode;
  },

  getTypeName: function () {
    return t("pim.field-type.input-with-placeholders");
  },

  getIconClass: function () {
    return "opendxp_icon_input_with_variables";
  },
});