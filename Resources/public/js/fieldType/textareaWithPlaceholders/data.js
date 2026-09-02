/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.classes.data.dataBridgeTextareaWithPlaceholders");
opendxp.object.classes.data.dataBridgeTextareaWithPlaceholders = Class.create(opendxp.object.classes.data.textarea, {
  type: "dataBridgeTextareaWithPlaceholders",

  initialize: function (treeNode, initData) {
    this.type = "dataBridgeTextareaWithPlaceholders";

    this.initData(initData);

    this.treeNode = treeNode;
  },

  getTypeName: function () {
    return t("pim.field-type.textarea-with-placeholders");
  },

  getIconClass: function () {
    return "opendxp_icon_textarea_with_variables";
  },
});