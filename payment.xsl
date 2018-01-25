	<xsl:template match="purchasing[@stage = 'payment'][@step = 'fondy']">
			<div>
				<xsl:text>&payment-redirect-text; Fondy.</xsl:text>
				<a href="{url}" class="button big">&pay;</a>
			</div>
		<xsl:call-template name="form-send" />
	</xsl:template>
