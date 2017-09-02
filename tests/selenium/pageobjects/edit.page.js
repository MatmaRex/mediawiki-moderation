'use strict';
const Page = require( './page' );

class EditPage extends Page {

	get content() { return browser.element( '#wpTextbox1' ); }
	get displayedContent() { return browser.element( '#mw-content-text' ); }
	get heading() { return browser.element( '#firstHeading' ); }
	get save() { return browser.element( '[name="wpSave"]' ); }

	open( name ) {
		super.open( name + '?action=edit&hidewelcomedialog=true' );
	}

	edit( name, content ) {
		this.open( name );
		this.content.setValue( content );
		this.submitAndWait( this.save );
	}

}
module.exports = new EditPage();
