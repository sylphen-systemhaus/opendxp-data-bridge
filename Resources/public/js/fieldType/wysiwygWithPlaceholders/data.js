/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.classes.data.dataBridgeWysiwygWithPlaceholders");
opendxp.object.classes.data.dataBridgeWysiwygWithPlaceholders = Class.create(opendxp.object.classes.data.wysiwyg, {
  type: "dataBridgeWysiwygWithPlaceholders",

  initialize: function (treeNode, initData) {
    this.type = "dataBridgeWysiwygWithPlaceholders";

    this.initData(initData);

    this.treeNode = treeNode;
  },

  getTypeName: function () {
    return t("pim.field-type.wysiwyg-with-placeholders");
  },

  getIconClass: function () {
    return "opendxp_icon_wysiwyg_with_variables";
  },
});